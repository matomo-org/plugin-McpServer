#!/usr/bin/env bash
set -euo pipefail

SMOKE_PROVIDER=${SMOKE_PROVIDER:?SMOKE_PROVIDER is required}
BASE_URL=${BASE_URL:?BASE_URL is required}
STATE_FILE=${STATE_FILE:?STATE_FILE is required}
CASES_FILE=${CASES_FILE:?CASES_FILE is required}
PROMPTS_DIR=${PROMPTS_DIR:?PROMPTS_DIR is required}
ARTIFACT_DIR=${ARTIFACT_DIR:?ARTIFACT_DIR is required}
MATOMO_LOG_FILE=${MATOMO_LOG_FILE:?MATOMO_LOG_FILE is required}

if [ "$SMOKE_PROVIDER" != "codex" ] && [ "$SMOKE_PROVIDER" != "claude" ]; then
  echo "Unsupported SMOKE_PROVIDER: $SMOKE_PROVIDER" >&2
  exit 1
fi

CASE_TIMEOUT_SECONDS=${CASE_TIMEOUT_SECONDS:-120}

MCP_URL="${BASE_URL}/index.php?module=API&method=McpServer.mcp&format=mcp"
TOKEN_AUTH=$(jq -r '.token_auth' "$STATE_FILE")

provider_artifact_dir="$ARTIFACT_DIR/$SMOKE_PROVIDER"
provider_transcripts_dir="$provider_artifact_dir/transcripts"
provider_logs_dir="$provider_artifact_dir/logs"
provider_results_dir="$provider_artifact_dir/results"
provider_state_root="${RUNNER_TEMP:-}"

if [ -z "$provider_state_root" ]; then
  provider_state_root=$(mktemp -d /tmp/mcp-smoke-state.XXXXXX)
else
  provider_state_root="$provider_state_root/mcp-smoke-state"
fi

provider_state_dir="$provider_state_root/$SMOKE_PROVIDER"

mkdir -p \
  "$provider_transcripts_dir" \
  "$provider_logs_dir" \
  "$provider_results_dir" \
  "$provider_state_dir"

cleanup() {
  unset OPENAI_APIKEY MCP_AUTH_TOKEN

  if [ -n "${provider_state_dir:-}" ] && [ -d "$provider_state_dir" ]; then
    rm -rf "$provider_state_dir"
  fi

  if [ -n "${provider_state_root:-}" ] \
    && [ -d "$provider_state_root" ] \
    && [ -z "$(find "$provider_state_root" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
    rmdir "$provider_state_root" 2>/dev/null || true
  fi
}

trap cleanup EXIT

if [ "$CASE_TIMEOUT_SECONDS" -gt 0 ] && ! command -v timeout >/dev/null 2>&1; then
  echo "timeout command not found but CASE_TIMEOUT_SECONDS=$CASE_TIMEOUT_SECONDS is configured" >&2
  exit 1
fi

escape_sed_replacement() {
  printf '%s' "$1" | sed -e 's/[\\&|]/\\&/g'
}

render_prompt() {
  local input_file="$1"
  local output_file="$2"
  local escaped_value
  cp "$input_file" "$output_file"

  while IFS='=' read -r key value; do
    escaped_value=$(escape_sed_replacement "$value")
    sed -i "s|{{${key}}}|${escaped_value}|g" "$output_file"
  done < <(jq -r 'to_entries[] | "\(.key)=\(.value)"' "$STATE_FILE")
}

setup_provider() {
  case "$SMOKE_PROVIDER" in
    codex)
      OPENAI_APIKEY=${OPENAI_APIKEY:?OPENAI_APIKEY is required}
      CODEX_MODEL=${CODEX_MODEL:-gpt-5-mini}
      CODEX_CLI_CMD=${CODEX_CLI_CMD:-codex}
      CODEX_HOME_DIR="$provider_state_dir/home"

      if ! command -v "$CODEX_CLI_CMD" >/dev/null 2>&1; then
        echo "Codex CLI command not found: $CODEX_CLI_CMD" >&2
        exit 1
      fi

      mkdir -p "$CODEX_HOME_DIR"
      HOME="$CODEX_HOME_DIR" "$CODEX_CLI_CMD" --version >/dev/null 2>&1 || true
      if printf '%s\n' "$OPENAI_APIKEY" \
        | HOME="$CODEX_HOME_DIR" "$CODEX_CLI_CMD" login --with-api-key \
        >/dev/null 2>&1; then
        :
      else
        echo "Codex API-key login failed." >&2
        exit 1
      fi
      unset OPENAI_APIKEY
      if HOME="$CODEX_HOME_DIR" "$CODEX_CLI_CMD" login status >/dev/null 2>&1; then
        :
      else
        echo "Codex API-key login verification failed." >&2
        exit 1
      fi
      HOME="$CODEX_HOME_DIR" "$CODEX_CLI_CMD" mcp remove matomo >/dev/null 2>&1 || true
      MCP_AUTH_TOKEN="$TOKEN_AUTH" HOME="$CODEX_HOME_DIR" "$CODEX_CLI_CMD" mcp add matomo \
        --url "$MCP_URL" \
        --bearer-token-env-var MCP_AUTH_TOKEN \
        >/dev/null 2>&1
      ;;
    *)
      echo "Unsupported SMOKE_PROVIDER: $SMOKE_PROVIDER" >&2
      exit 1
      ;;
  esac
}

run_provider() {
  local prompt_file="$1"
  local transcript_file="$2"

  # shellcheck disable=SC2086
  case "$SMOKE_PROVIDER" in
    codex)
      if [ "$CASE_TIMEOUT_SECONDS" -gt 0 ]; then
        MCP_AUTH_TOKEN="$TOKEN_AUTH" HOME="$CODEX_HOME_DIR" \
        timeout "${CASE_TIMEOUT_SECONDS}s" "$CODEX_CLI_CMD" \
          --config forced_login_method='"api"' \
          --ask-for-approval never \
          exec \
          --model "$CODEX_MODEL" \
          --skip-git-repo-check \
          --sandbox workspace-write \
          --color never \
          --output-last-message "$transcript_file" \
          - \
          > /dev/null 2>&1 < "$prompt_file"
      else
        MCP_AUTH_TOKEN="$TOKEN_AUTH" HOME="$CODEX_HOME_DIR" \
        "$CODEX_CLI_CMD" \
          --config forced_login_method='"api"' \
          --ask-for-approval never \
          exec \
          --model "$CODEX_MODEL" \
          --skip-git-repo-check \
          --sandbox workspace-write \
          --color never \
          --output-last-message "$transcript_file" \
          - \
          > /dev/null 2>&1 < "$prompt_file"
      fi
      ;;
  esac
}

write_case_result() {
  local result_file="$1"
  local case_id="$2"
  local tool="$3"
  local status="$4"
  local reason="$5"

  jq -n \
    --arg stage "${SMOKE_PROVIDER}_case" \
    --arg case_id "$case_id" \
    --arg expected_tool "$tool" \
    --arg observed_tool "" \
    --arg status "$status" \
    --arg reason "$reason" \
    --arg transcript_path "$SMOKE_PROVIDER/transcripts/${case_id}.txt" \
    --arg log_path "$SMOKE_PROVIDER/logs/${case_id}.log" \
    '{stage:$stage,case_id:$case_id,expected_tool:$expected_tool,observed_tool:$observed_tool,status:$status,reason:$reason,transcript_path:$transcript_path,log_path:$log_path}' \
    > "$result_file"
}

setup_provider

result_count=0
pass_count=0
skip_count=0

while IFS= read -r case_json; do
  case_id=$(echo "$case_json" | jq -r '.id')
  tool=$(echo "$case_json" | jq -r '.tool')
  prompt_file=$(echo "$case_json" | jq -r '.prompt_file')

  rendered_prompt="$provider_transcripts_dir/${case_id}.prompt.txt"
  transcript_file="$provider_transcripts_dir/${case_id}.txt"
  log_slice_file="$provider_logs_dir/${case_id}.log"
  result_file="$provider_results_dir/${case_id}.json"

  if jq -e --arg case_id "$case_id" '.skip_cases // [] | index($case_id) != null' "$STATE_FILE" >/dev/null 2>&1; then
    write_case_result "$result_file" "$case_id" "$tool" "skip" "missing_fixture_dependency"
    : > "$transcript_file"
    : > "$log_slice_file"
    result_count=$((result_count + 1))
    skip_count=$((skip_count + 1))
    continue
  fi

  render_prompt "$PROMPTS_DIR/$prompt_file" "$rendered_prompt"
  {
    echo
    echo "[mcp-smoke-case:${case_id}]"
    cat "$rendered_prompt"
  } > "${rendered_prompt}.tmp"
  mv "${rendered_prompt}.tmp" "$rendered_prompt"

  case_start_marker="MCP_SMOKE_CASE_START:${case_id}"
  case_end_marker="MCP_SMOKE_CASE_END:${case_id}"
  if [ -f "$MATOMO_LOG_FILE" ]; then
    printf '%s\n' "$case_start_marker" >> "$MATOMO_LOG_FILE"
  fi

  set +e
  run_provider "$rendered_prompt" "$transcript_file"
  cmd_exit=$?
  set -e

  if [ -f "$MATOMO_LOG_FILE" ]; then
    printf '%s\n' "$case_end_marker" >> "$MATOMO_LOG_FILE"
    awk -v s="$case_start_marker" -v e="$case_end_marker" '
      $0 ~ s {in_range=1; next}
      $0 ~ e {in_range=0}
      in_range
    ' "$MATOMO_LOG_FILE" > "$log_slice_file"
  else
    : > "$log_slice_file"
  fi

  status="fail"
  reason=""
  if [ "$cmd_exit" -eq 124 ]; then
    reason="${SMOKE_PROVIDER}_timeout"
  elif [ "$cmd_exit" -ne 0 ]; then
    reason="${SMOKE_PROVIDER}_command_failed"
  elif grep -Fq "MCP Tool Call successful: ${tool} [" "$log_slice_file"; then
    # Require the success line for the expected tool itself, not merely any
    # successful call plus a separate mention of the tool name. The Matomo log
    # emits "MCP Tool Call successful: <tool_name> [<arguments>]" on one line.
    status="pass"
    reason="tool success evidence found"
  elif grep -Eq "MCP Tool Call (successful|failed):" "$log_slice_file"; then
    reason="tool success evidence missing"
  else
    reason="missing_mcp_call_evidence"
  fi

  write_case_result "$result_file" "$case_id" "$tool" "$status" "$reason"

  result_count=$((result_count + 1))
  if [ "$status" = "pass" ]; then
    pass_count=$((pass_count + 1))
  fi
done < <(jq -c '.[]' "$CASES_FILE")

if find "$provider_results_dir" -maxdepth 1 -type f -name '*.json' | grep -q .; then
  jq -s '.' "$provider_results_dir"/*.json > "$provider_artifact_dir/results.json"
else
  echo '[]' > "$provider_artifact_dir/results.json"
fi

failure_count=$((result_count - pass_count - skip_count))
echo "${SMOKE_PROVIDER^} smoke: $pass_count passed, $skip_count skipped, $failure_count failed ($result_count total)"
if [ "$failure_count" -ne 0 ] || [ "$skip_count" -ne 0 ]; then
  exit 1
fi
