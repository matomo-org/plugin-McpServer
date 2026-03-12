## Changelog

### Unreleased

- Aligned `matomo_report_processed` so empty `resolvedReport.apiParameters` values serialize as `{}` rather than `[]`, matching the declared MCP output schema.
- Updated `matomo_report_processed` and `matomo_report_metadata` to accept `apiParameters: []` as the empty-input compatibility form.

### 5.0.1

- Marked the plugin as not compatible with WordPress installations of Matomo

### 5.0.0

- Initial release
- Added MCP over HTTP endpoint
- Added tools for sites, reports, goals, segments, and dimensions
- Added admin page for MCP client setup
