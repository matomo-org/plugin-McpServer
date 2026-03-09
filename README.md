# Matomo MCP Server Plugin

## Description

McpServer adds a secure Model Context Protocol (MCP) endpoint to Matomo so AI assistants and MCP-compatible clients can work with analytics context directly from your Matomo instance.

It provides read-oriented tools for sites, reports, processed report data, goals, segments, and dimensions using the same Matomo authentication and access rules you already use in Matomo.

## Setup

1. Install the plugin in Matomo.
2. Activate **McpServer** in **Administration -> Plugins**.
3. Enable MCP in **Administration -> System -> Plugin Settings -> McpServer**.
4. Configure your MCP client with the endpoint and a Matomo `token_auth` that already has access to the data you want to expose.

For the recommended end-user setup flow, use the in-product connect guide at **Administration -> Platform -> MCP Server**.

## Security And Access Model

- MCP access is disabled by default.
- The plugin uses Matomo authentication.
- Data access is limited to the same sites and reports the Matomo user can already access.
- If features such as the Visitor Log are available to that user, MCP clients may access the same underlying data scope.

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

## Supported MCP Capabilities

The plugin is focused on read-oriented analytics workflows. The exact tool surface may expand over time, but the initial release includes tools around:

- sites
- reports and report metadata
- processed report data
- goals
- segments
- dimensions

## Troubleshooting

- `401 Unauthorized`: verify the Bearer token is present, active, and belongs to a user with access to the requested site data.
- `403 Forbidden` when MCP is disabled: enable MCP in **Administration -> System -> Plugin Settings -> McpServer**.
- `403 Forbidden` for authenticated requests: verify the authenticated Matomo user has access to the requested site or report data.
- `400 Bad Request`: verify the client is using the exact MCP endpoint and is not proxying requests through `API.getBulkRequest`.

## Support

- Issues: <https://github.com/matomo-org/plugin-McpServer/issues>
- Forum: <https://forum.matomo.org>
- Source: <https://github.com/matomo-org/plugin-McpServer>

## License

GPL v3 or later
