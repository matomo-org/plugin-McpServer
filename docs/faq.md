# McpServer FAQ

## Endpoint

Use the API endpoint:

`index.php?module=API&method=McpServer.mcp&format=mcp`

- `format=mcp` is required.
- The endpoint is root-request only and rejects nested/proxy access (including `API.getBulkRequest`) with `400`.
- Unauthenticated requests return `401` with `WWW-Authenticate: Bearer realm="mcp"`.
- Authenticate with Matomo credentials by sending a Bearer token. If your MCP client supports OAuth2 and the Matomo `OAuth2` plugin is installed and enabled, OAuth2 is the recommended option; create an OAuth2 client there if needed. Otherwise use a Matomo `token_auth` as the Bearer token.

## Configuration

Configure options in `config/config.ini.php`:

```ini
[McpServer]
session_ttl = 3600
log_tool_calls = 0
log_tool_call_level = DEBUG
log_tool_call_parameters_full = 0
```

- `session_ttl`: Session TTL in seconds. Default is `3600` if missing or invalid.
- `log_tool_calls`: Enables tool-call logging when set to `1`. Default is disabled when missing or set to `0`.
- `log_tool_call_level`: Tool-call logging level when `log_tool_calls = 1`. Accepted values: `ERROR`, `WARN`/`WARNING`, `INFO`, `DEBUG`, `VERBOSE` (case-insensitive). Missing or invalid values default to `DEBUG`. `VERBOSE` is logged via debug-level logger calls.
- `log_tool_call_parameters_full`: Logs full tool-call parameter values when set to `1`. Default is redacted parameter logging when set to `0` (may expose sensitive input data when enabled).

Configure raw Matomo API tool access in `config/config.ini.php`:

```ini
[McpServer]
raw_api_access_mode = none
```

- `raw_api_access_mode`: Controls raw API discovery tool visibility for `matomo_api_list` and `matomo_api_get`.
- `none`: hides `matomo_api_list` and `matomo_api_get` (default).
- `read`: shows `matomo_api_list` and `matomo_api_get`, and currently returns only API actions with `get`/`is` prefix. This prefix-based filter is a temporary heuristic and may be replaced by a more accurate read/write classification in the future.
- `full`: shows `matomo_api_list` and `matomo_api_get`, and returns all discoverable API actions.

## Enabling MCP

MCP access is disabled by default and must be enabled in **Administration -> System -> General Settings -> McpServer**.

The Matomo `OAuth2` plugin is not required to use McpServer. If it is installed and enabled, OAuth2 is available for compatible MCP clients; create an OAuth2 client in that plugin if needed. Otherwise clients can connect with a Matomo `token_auth` as a Bearer token.

When disabled, requests to `index.php?module=API&method=McpServer.mcp&format=mcp` behave as follows:

- Unauthenticated requests receive `401 Unauthorized` with `WWW-Authenticate: Bearer realm="mcp"`.
- Authenticated requests with a top-level JSON-RPC `id` receive `403 Forbidden` with a JSON-RPC error response instructing the user to contact their Matomo administrator.
- Authenticated requests without a top-level `id` (for example notifications, invalid JSON, or batch payloads) receive `403 Forbidden` with an empty body.

## Supported MCP Capabilities

The plugin is focused on read-oriented analytics workflows. The exact tool surface may expand over time, but the initial release includes tools around:

- sites
- reports and report metadata
- processed report data
- goals
- segments
- dimensions

`matomo_report_processed` is advertised to MCP clients as read-only only when Matomo is configured so report requests do not trigger browser-based archiving work. In practice, if browser-triggered archiving is enabled or browser-based segment archiving is available, MCP clients will see this tool as not read-only.

To change how AI clients see this tool, adjust the Matomo archiving settings that control browser-triggered archiving and browser-based segment archiving. Even when the tool is advertised as read-only, Matomo may still materialize a cached range aggregate while serving the report, and this plugin treats that derived cache work as non-mutational for MCP classification. The tool is still not advertised as idempotent, because repeated calls can differ in internal processing effects and archive reuse.

## Troubleshooting

- `401 Unauthorized`: verify the Bearer token is present and active. If you use OAuth2, verify the client completed authorization successfully and is sending a valid access token. If you use `token_auth`, verify you are sending `Authorization: Bearer <token_auth>` and that the token belongs to a user with access to the requested site data.
- `403 Forbidden`: if MCP is disabled, enable MCP in **Administration -> System -> General Settings -> McpServer**. If MCP is already enabled, verify the authenticated Matomo user behind the OAuth2 access token or `token_auth` has access to the requested site or report data.
- `400 Bad Request`: verify the client is using the exact MCP endpoint and is not proxying requests through `API.getBulkRequest`.
