# McpServer FAQ

## Endpoint

Use the API endpoint:

`index.php?module=API&method=McpServer.mcp&format=mcp`

- `format=mcp` is required.
- The endpoint is root-request only and rejects nested/proxy access (including `API.getBulkRequest`) with `400`.
- Unauthenticated requests return `401` with `WWW-Authenticate: Bearer realm="mcp"`. When OAuth2 metadata discovery is available, the challenge also includes `resource_metadata` pointing to the McpServer protected-resource metadata endpoint.
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

Configure raw Matomo API tool access in **Administration -> System -> General Settings -> McpServer**:

- Use the **Raw Matomo API tool access** drop-down to control visibility for `matomo_api_list`, `matomo_api_get`, and the raw API call tools.
- `No API access` (default): hides all raw API discovery and execution tools.
- `Partial API access`: shows `matomo_api_get`, `matomo_api_list`, and only the CRUD-specific execution tools enabled by the **Read methods**, **Create methods**, **Update methods**, and **Delete methods** checkboxes. Each checkbox is independent — selecting Create does not automatically include Read; check both if you want both.
- `Full API access`: shows `matomo_api_get`, `matomo_api_list`, all CRUD-specific execution tools, and `matomo_api_call_full` for non-restricted methods that need unrestricted execution.
- The dedicated report tools remain available independently of this setting.
- Permanently restricted methods in `RawApiMethodPolicy` remain blocked in every mode.
- Low-confidence or unclassified direct API methods require `Full API access`.
- Direct API access can expose raw or personal data depending on enabled Matomo features. Review privacy and security requirements before enabling it, and consult your DPO or compliance owner when needed.

Configure MCP privilege limits in **Administration -> System -> General Settings -> McpServer**:

- Use **Maximum allowed MCP privilege level** to deny MCP access for users authenticated with a higher Matomo privilege.
- `No privilege limit` (default): follows the usual Matomo access model and does not add an extra MCP privilege cap.
- `View access`, `Write access`, or `Admin access`: allows only users whose highest privilege across all sites is at or below the selected level.
- For stricter separation, create a separate Matomo user or token with reduced permissions for MCP use.

## Enabling MCP

MCP access is disabled by default and must be enabled in **Administration -> System -> General Settings -> McpServer**.

The Matomo `OAuth2` plugin is not required to use McpServer. If it is installed and enabled, OAuth2 is available for compatible MCP clients; create an OAuth2 client in that plugin if needed. Otherwise clients can connect with a Matomo `token_auth` as a Bearer token.

When disabled, requests to `index.php?module=API&method=McpServer.mcp&format=mcp` behave as follows:

- Unauthenticated requests receive `401 Unauthorized` with `WWW-Authenticate: Bearer realm="mcp"`. When OAuth2 metadata discovery is available, the challenge also includes `resource_metadata` pointing to the McpServer protected-resource metadata endpoint.
- Authenticated requests with a top-level JSON-RPC `id` receive `403 Forbidden` with a JSON-RPC error response instructing the user to contact their Matomo administrator.
- Authenticated requests without a top-level `id` (for example notifications, invalid JSON, or batch payloads) receive `403 Forbidden` with an empty body.

## Supported MCP Capabilities

The plugin is focused on read-oriented analytics workflows. The exact tool surface may expand over time, but the initial release includes tools around:

- sites
- reports, report metadata, and processed report data
- goals
- segments
- dimensions
- raw Matomo API discovery and execution, when enabled by an administrator

`matomo_report_processed` is advertised to MCP clients as read-only only when Matomo is configured so report requests do not trigger browser-based archiving work. In practice, if browser-triggered archiving is enabled or browser-based segment archiving is available, MCP clients will see this tool as not read-only.

To change how AI clients see this tool, adjust the Matomo archiving settings that control browser-triggered archiving and browser-based segment archiving. Even when the tool is advertised as read-only, Matomo may still materialize a cached range aggregate while serving the report, and this plugin treats that derived cache work as non-mutational for MCP classification. The tool is still not advertised as idempotent, because repeated calls can differ in internal processing effects and archive reuse.

## Troubleshooting

- `401 Unauthorized`: verify the Bearer token is present and active. If you use OAuth2, verify the client completed authorization successfully and is sending a valid access token. If you use `token_auth`, verify you are sending `Authorization: Bearer <token_auth>` and that the token belongs to a user with access to the requested site data.
- `403 Forbidden`: if MCP is disabled, enable MCP in **Administration -> System -> General Settings -> McpServer**. If MCP is already enabled, verify the authenticated Matomo user behind the OAuth2 access token or `token_auth` has access to the requested site or report data and does not exceed the configured maximum MCP privilege level
- `400 Bad Request`: verify the client is using the exact MCP endpoint and is not proxying requests through `API.getBulkRequest`.
