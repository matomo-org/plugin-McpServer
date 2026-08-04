## Changelog

### 5.1.0

- Updated `mcp/sdk` to 0.7.
- Added a search filter to `matomo_report_list` for finding reports by name, category, or uniqueId.
- Added `McpServer.addTools` and `McpServer.filterTools` events so other plugins can contribute or restrict MCP tool registrations.
- Added the `McpServer.serverEvent` event so other plugins can observe completed MCP requests, received notifications, and explicit session termination through SDK-agnostic events, with richer payloads for initialization and tool activity.
- Clarified the date/period semantics in the `matomo_report_processed` tool description.
- Made the report and API discovery tools tolerant of the input shapes real MCP clients commonly send, so a plausible-but-imprecise argument is accepted instead of failing or resolving to something unintended: report selectors work in the API-method form (`VisitsSummary.get`) as well as the report uniqueId form (`VisitsSummary_get`), whole-bucket shorthand dates are expanded to a full date (`period=year` with `2026`, `period=month` with `2026-01`), stringified numbers are accepted for integer arguments, and `search` filters ignore spaces, underscores, hyphens, and dots.
- Hardened MCP endpoint `Origin` handling for DNS-rebinding protection: a supplied `Origin` is validated against Matomo's trusted deployment hostnames (`[General] trusted_hosts`) and rejected with `403` when it falls outside them, even when `[General] enable_trusted_host_check` is disabled. This is request validation, not CORS support — direct cross-origin browser MCP remains unsupported. Requests without an `Origin` (native MCP clients) are unaffected by this validation.

### 5.0.4

- Updated `mcp/sdk` to 0.5.
- Surfaced nested SegmentEditor validation messages through MCP tool errors instead of collapsing them to the generic failure message.
- Aligned the declared MCP `outputSchema` for the `matomo_api_*` tools so the `result` and `defaultValue` entries serialize as `{}` rather than `[]`, matching the MCP requirement that every schema value is a JSON object.

### 5.0.3

- Added the missing PHP version requirement to `plugin.json` to reflect the plugin's actual runtime requirement.
- Updated the connect guidance so only superusers see the OAuth2 client management link, while other users are told to contact a superuser.

### 5.0.2

- Disabled anonymous access to the MCP API endpoint and connect guidance page.
- Added raw Matomo API discovery and execution MCP tools, with administrator-controlled access modes and privilege limits.
- Added support for exposing subtable reports through the MCP report tools.
- Aligned `matomo_report_processed` so empty `resolvedReport.apiParameters` values serialize as `{}` rather than `[]`, matching the declared MCP output schema.
- Updated `matomo_report_processed` and `matomo_report_metadata` to accept `apiParameters: []` as the empty-input compatibility form.
- Added the full set of MCP tool annotations, including `destructiveHint`, to improve client compatibility and provide explicit tool metadata.
- Updated `matomo_report_processed` so MCP clients see it as read-only when Matomo is configured to avoid browser-triggered archiving for normal and segmented report requests, while still keeping the tool non-idempotent.

### 5.0.1

- Marked the plugin as not compatible with WordPress installations of Matomo

### 5.0.0

- Initial release
- Added MCP over HTTP endpoint
- Added tools for sites, reports, goals, segments, and dimensions
- Added admin page for MCP client setup
