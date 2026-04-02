#!/usr/bin/env bash
set -euo pipefail

MATOMO_DIR=${MATOMO_DIR:?MATOMO_DIR is required}
BASE_URL=${BASE_URL:-http://127.0.0.1:8000}
MYSQL_HOST=${MYSQL_HOST:?MYSQL_HOST is required}
MYSQL_PORT=${MYSQL_PORT:-3306}
MYSQL_USER=${MYSQL_USER:?MYSQL_USER is required}
MYSQL_PASSWORD=${MYSQL_PASSWORD:?MYSQL_PASSWORD is required}
MYSQL_DATABASE=${MYSQL_DATABASE:?MYSQL_DATABASE is required}
STATE_FILE=${STATE_FILE:-$PWD/.github/scripts/mcp-smoke/.state.json}

export MYSQL_PWD="$MYSQL_PASSWORD"

wait_for_mysql() {
  for _ in $(seq 1 60); do
    if mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -e 'SELECT 1' >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  echo "MySQL did not become ready in time" >&2
  return 1
}

write_config() {
  cat > "$MATOMO_DIR/config/config.ini.php" <<EOF
; <?php exit; ?> DO NOT REMOVE THIS LINE
[database]
host = "$MYSQL_HOST"
username = "$MYSQL_USER"
password = "$MYSQL_PASSWORD"
dbname = "$MYSQL_DATABASE"
tables_prefix = ""
charset = "utf8mb4"
collation = "utf8mb4_general_ci"

[General]
trusted_hosts[] = "127.0.0.1"
enable_logging = 1

[log]
log_writers[] = file
log_level = DEBUG
logger_file_path = tmp/logs/matomo.log

[McpServer]
enable_mcp = 1
log_tool_calls = 1
log_tool_call_level = DEBUG
log_tool_call_parameters_full = 1
maximum_mcp_access_level = unlimited
raw_api_access_scope = full
EOF
}

start_php_server() {
  # Bind php -S to the authority of BASE_URL so the server address stays in sync
  # with the URL every other step probes (strip the scheme, then any path).
  local bind_addr="${BASE_URL#*://}"
  bind_addr="${bind_addr%%/*}"

  mkdir -p "$MATOMO_DIR/tmp/logs"
  : > "$MATOMO_DIR/tmp/logs/matomo.log"
  php -S "$bind_addr" -t "$MATOMO_DIR" > "$MATOMO_DIR/tmp/logs/php-server.log" 2>&1 &
  echo $! > "$MATOMO_DIR/tmp/php-server.pid"

  for _ in $(seq 1 60); do
    if curl -sS "$BASE_URL/index.php" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done

  echo "PHP server did not start in time" >&2
  return 1
}

api_post_json() {
  local token="$1"
  local method="$2"
  shift 2

  curl -sS -X POST "$BASE_URL/index.php" \
    --data-urlencode "module=API" \
    --data-urlencode "method=${method}" \
    --data-urlencode "format=JSON" \
    --data-urlencode "token_auth=${token}" \
    "$@" \
    | sed 's/^\xEF\xBB\xBF//'
}

require_non_empty() {
  local key="$1"
  local value="$2"
  if [ -z "$value" ] || [ "$value" = "null" ]; then
    echo "Required fixture value '$key' is missing from OmniFixture/API discovery." >&2
    exit 1
  fi
}

extract_php_constant() {
  local file="$1"
  local constant_name="$2"

  php -r '
    $file = $argv[1];
    $constantName = $argv[2];
    $content = @file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, "Unable to read file: {$file}\n");
        exit(2);
    }

    $linePattern = "/^\\s*public\\s+const\\s+" . preg_quote($constantName, "/") . "\\s*=\\s*\\x27(.*)\\x27;\\s*$/";
    foreach (preg_split("/\\R/", $content) as $line) {
        if (preg_match($linePattern, $line, $matches)) {
            echo stripcslashes($matches[1]);
            exit(0);
        }
    }

    fwrite(STDERR, "Could not extract constant {$constantName} from {$file}\n");
    exit(3);
  ' "$file" "$constant_name"
}

main() {
  wait_for_mysql

  mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" "$MYSQL_DATABASE" < "$MATOMO_DIR/tests/resources/OmniFixture-dump.sql"

  write_config
  start_php_server

  # Run updates as the first Matomo console interaction after startup.
  (cd "$MATOMO_DIR" && php ./console core:update --yes)

  (cd "$MATOMO_DIR" && php ./console plugin:activate McpServer)
  if ! (cd "$MATOMO_DIR" && php ./console plugin:list | grep -qE '\|[[:space:]]*McpServer[[:space:]]*\|.*\|[[:space:]]*Activated[[:space:]]*\|'); then
    echo "McpServer plugin is not activated after setup." >&2
    exit 1
  fi

  local fixture_file="$MATOMO_DIR/tests/PHPUnit/Framework/Fixture.php"
  local omnifixture_file="$MATOMO_DIR/tests/PHPUnit/Fixtures/OmniFixture.php"
  local fixture_admin_login
  local fixture_admin_password
  local omnifixture_superuser_token
  fixture_admin_login=$(extract_php_constant "$fixture_file" "ADMIN_USER_LOGIN")
  fixture_admin_password=$(extract_php_constant "$fixture_file" "ADMIN_USER_PASSWORD")
  omnifixture_superuser_token=$(extract_php_constant "$omnifixture_file" "OMNIFIXTURE_SUPERUSER_TOKEN")

  require_non_empty "ADMIN_USER_LOGIN" "$fixture_admin_login"
  require_non_empty "ADMIN_USER_PASSWORD" "$fixture_admin_password"
  require_non_empty "OMNIFIXTURE_SUPERUSER_TOKEN" "$omnifixture_superuser_token"

  local token_response
  token_response=$(curl -sS -X POST "$BASE_URL/index.php" \
    --data-urlencode "module=API" \
    --data-urlencode "method=UsersManager.createAppSpecificTokenAuth" \
    --data-urlencode "format=JSON" \
    --data-urlencode "token_auth=${omnifixture_superuser_token}" \
    --data-urlencode "userLogin=${fixture_admin_login}" \
    --data-urlencode "passwordConfirmation=${fixture_admin_password}" \
    --data-urlencode "description=mcp smoke ci token")

  local token
  if ! echo "$token_response" | jq -e 'type == "string" or (type == "object" and ((.value // .token_auth // .token // null) | type == "string"))' >/dev/null 2>&1; then
    echo "Unexpected token response schema from UsersManager.createAppSpecificTokenAuth." >&2
    exit 1
  fi
  token=$(echo "$token_response" | jq -r 'if type == "string" then . else (.value // .token_auth // .token // empty) end')
  if [ -z "$token" ] || [ "$token" = "null" ] || [ "$token" = "false" ]; then
    echo "Failed to create app token via API." >&2
    exit 1
  fi

  local sites_json metadata_json
  local id_site report_unique_id
  local -a skip_cases

  sites_json=$(api_post_json "$token" "SitesManager.getSitesWithAtLeastViewAccess")
  id_site=$(echo "$sites_json" | jq -r '.[0].idsite // empty')
  require_non_empty "idSite" "$id_site"

  metadata_json=$(api_post_json "$token" "API.getReportMetadata" --data-urlencode "idSite=${id_site}")
  report_unique_id=$(echo "$metadata_json" | jq -r '.[] | select(.module=="Actions" and .action=="getPageUrls") | .uniqueId' | head -n1)
  if [ -z "$report_unique_id" ]; then
    report_unique_id=$(echo "$metadata_json" | jq -r '.[] | .uniqueId // empty' | head -n1)
  fi
  if [ -z "$report_unique_id" ]; then
    skip_cases+=("report_processed")
  fi

  jq -n \
    --arg base_url "$BASE_URL" \
    --arg token_auth "$token" \
    --arg idSite "$id_site" \
    --arg reportUniqueId "$report_unique_id" \
    --argjson skip_cases "$(printf '%s\n' "${skip_cases[@]:-}" | sed '/^$/d' | jq -R . | jq -s 'unique')" \
    '{base_url: $base_url, token_auth: $token_auth, idSite: $idSite, reportUniqueId: $reportUniqueId, skip_cases: $skip_cases}' > "$STATE_FILE"

  echo "State written to $STATE_FILE"
}

main "$@"
