## Changelog

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
