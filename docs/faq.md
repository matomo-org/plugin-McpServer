# McpServer FAQ

## Endpoint

Use the API endpoint:

`index.php?module=API&method=McpServer.mcp&format=mcp`

- `format=mcp` is required.
- The endpoint is root-request only and rejects nested/proxy access (including `API.getBulkRequest`) with `400`.
- Unauthenticated requests return `401` with `WWW-Authenticate: Bearer realm="mcp"`.
- Authenticate with Matomo credentials by sending a Bearer token. If your MCP client supports OAuth2 and the Matomo `OAuth2` plugin is installed and enabled, OAuth2 is the recommended option; create an OAuth2 client there if needed. Otherwise use a Matomo `token_auth` as the Bearer token.
- Direct cross-origin browser MCP is not supported. When a request supplies an `Origin` header, its host is validated (for DNS-rebinding protection) against Matomo's trusted deployment hostnames (`[General] trusted_hosts`): an `Origin` outside those hosts is rejected with `403`. This is request validation, not CORS support. Matomo may emit `Access-Control-Allow-Origin` according to `[General] cors_domains`, but the MCP endpoint does not provide the complete CORS flow required by browser clients: it does not handle preflight, and a CORS preflight (`OPTIONS`) cannot succeed because it arrives without the Bearer token. This `Origin` validation stays active even when `[General] enable_trusted_host_check` is disabled (that setting only affects requests without an `Origin`). Connect through an MCP client or a same-origin backend instead.

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

- Unauthenticated requests receive `401 Unauthorized` with `WWW-Authenticate: Bearer realm="mcp"`.
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

`matomo_report_processed` is always advertised to MCP clients as read-only and idempotent, regardless of the Matomo archiving settings. Matomo may materialize a cached archive while serving the report — including through browser-triggered archiving or browser-based segment archiving — and this plugin does not treat that derived cache work as a change to Matomo's state, because it never changes the reported data and repeating the call adds no further effect beyond the same cache work. Read-only does not mean cheap: a call covering dates or segments that have not been archived yet can still trigger substantial archiving work on the server.

## Argument Handling

Some tools accept equivalent ways of writing the same argument and rewrite them to the canonical form silently. A rewritten request is still validated against the tool's published input schema, and access checks and domain validation run afterwards, so this cannot widen what a caller may read or change.

Argument problems are reported on two channels. Failures against the tool's published input schema keep the JSON-RPC `-32602` (invalid params) error; this includes combining `reportUniqueId` with `apiModule` or `apiAction`, and a parameter object supplied as a string that is not visibly an object (a bare word or a quoted scalar keeps its schema type error). Problems found while normalizing arguments return a normal tool result with `isError: true`: contradictory raw-API selectors, conflicting aliases or parameter locations, a method name outside the `Module.action` form, a selector value longer than 256 bytes, and a parameter object string that opens as an object but does not decode as a bounded JSON object. These name the JSON pointers involved without repeating the values at them, so a segment expression or a token pasted into a parameter object is not echoed back.

A `segment` Matomo cannot parse, or one naming a field this Matomo does not provide, is not an argument problem in the sense above — it is reported as a tool result error naming the segment as the problem, without repeating the expression.

## Troubleshooting

- `401 Unauthorized`: verify the Bearer token is present and active. If you use OAuth2, verify the client completed authorization successfully and is sending a valid access token. If you use `token_auth`, verify you are sending `Authorization: Bearer <token_auth>` and that the token belongs to a user with access to the requested site data.
- `403 Forbidden`: if MCP is disabled, enable MCP in **Administration -> System -> General Settings -> McpServer**. If MCP is already enabled, verify the authenticated Matomo user behind the OAuth2 access token or `token_auth` has access to the requested site or report data and does not exceed the configured maximum MCP privilege level
- `400 Bad Request`: verify the client is using the exact MCP endpoint and is not proxying requests through `API.getBulkRequest`.
