# Matomo MCP Server Plugin

## Endpoint

Use the API endpoint:

`index.php?module=API&method=McpServer.mcp&format=mcp`

- `format=mcp` is required.
- The endpoint is root-request only and rejects nested/proxy access (including `API.getBulkRequest`) with `400`.
- Unauthenticated requests return `401` with `WWW-Authenticate: Bearer realm="mcp"`.

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

## Enabling MCP

MCP access is disabled by default and must be enabled in **Administration -> System -> Plugin Settings -> McpServer**.

When disabled, requests to `index.php?module=API&method=McpServer.mcp&format=mcp` behave as follows:

- Unauthenticated requests receive `401 Unauthorized` with `WWW-Authenticate: Bearer realm="mcp"`.
- Authenticated requests with a top-level JSON-RPC `id` receive `403 Forbidden` with a JSON-RPC error response instructing the user to contact their Matomo administrator.
- Authenticated requests without a top-level `id` (for example notifications, invalid JSON, or batch payloads) receive `403 Forbidden` with an empty body.

## License

GPL v3 or later
