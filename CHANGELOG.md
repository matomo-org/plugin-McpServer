## Changelog

### Unreleased

- Added support for exposing subtable reports through the MCP report tools.
- Aligned `matomo_report_processed` so empty `resolvedReport.apiParameters` values serialize as `{}` rather than `[]`, matching the declared MCP output schema.
- Updated `matomo_report_processed` and `matomo_report_metadata` to accept `apiParameters: []` as the empty-input compatibility form.
- Added the full set of MCP tool annotations, including `destructiveHint`, to improve client compatibility and provide explicit tool metadata.
- Reclassified `matomo_report_processed` as read-only in MCP tool metadata, while documenting that Matomo may still materialize temporary or archive data internally during report generation and therefore remains non-idempotent.

### 5.0.1

- Marked the plugin as not compatible with WordPress installations of Matomo

### 5.0.0

- Initial release
- Added MCP over HTTP endpoint
- Added tools for sites, reports, goals, segments, and dimensions
- Added admin page for MCP client setup
