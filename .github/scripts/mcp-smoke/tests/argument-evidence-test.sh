#!/usr/bin/env bash
set -euo pipefail

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/../lib/argument-evidence.sh"

assert_matches() {
  local line="$1"
  local expected_argument="$2"

  if ! mcp_smoke_line_has_exact_argument "$line" "$expected_argument"; then
    echo "Expected argument evidence was not found: $expected_argument" >&2
    exit 1
  fi
}

assert_does_not_match() {
  local line="$1"
  local unexpected_argument="$2"

  if mcp_smoke_line_has_exact_argument "$line" "$unexpected_argument"; then
    echo "Unexpected argument evidence was found: $unexpected_argument" >&2
    exit 1
  fi
}

site_list_line='2026-07-21 DEBUG MCP Tool Call successful: matomo_site_list [limit: 5] [session=test]'
site_list_prefix_line='2026-07-21 DEBUG MCP Tool Call successful: matomo_site_list [limit: 50] [session=test]'
report_line='2026-07-21 DEBUG MCP Tool Call successful: matomo_report_processed [idSite: 10, filter_limit: 20, filter_offset: 0] [session=test, response_bytes=123]'

assert_matches "$site_list_line" 'limit: 5'
assert_matches "$report_line" 'idSite: 10'
assert_matches "$report_line" 'filter_limit: 20'
assert_matches "$report_line" 'filter_offset: 0'

assert_does_not_match "$site_list_prefix_line" 'limit: 5'
assert_does_not_match "$report_line" 'idSite: 1'
assert_does_not_match "$report_line" 'filter_limit: 2'
assert_does_not_match 'not an MCP success log line' 'limit: 5'
assert_does_not_match 'MCP Tool Call successful: matomo_site_list [limit: 5]' 'limit: 5'

echo "Argument evidence tests passed."
