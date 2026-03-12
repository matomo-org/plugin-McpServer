# Matomo MCP Server Plugin

## Description

McpServer is an early preview plugin that adds a secure Model Context Protocol (MCP) endpoint to Matomo so AI assistants and MCP-compatible clients can work with analytics context directly from your Matomo instance.

It provides read-oriented tools for sites, reports, processed report data, goals, segments, and dimensions using the same Matomo authentication and access rules you already use in Matomo.

### Setup

1. Install the plugin in Matomo.
2. Activate **McpServer** in **Administration -> Plugins**.
3. Enable MCP in **Administration -> System -> Plugin Settings -> McpServer**.
4. Configure your MCP client with the endpoint and a Matomo `token_auth` that already has access to the data you want to expose.

For the recommended end-user setup flow, use the in-product connect guide at **Administration -> Platform -> MCP Server**.

### Security And Access Model

- MCP access is disabled by default.
- The plugin uses Matomo authentication.
- Data access is limited to the same sites and reports the Matomo user can already access.
- If features such as the Visitor Log are available to that user, MCP clients may access the same underlying data scope.

### Additional Documentation

The FAQ includes additional technical documentation for endpoint details, configuration, MCP enablement behavior, supported capabilities, and troubleshooting.

## Support

- Issues: <https://github.com/matomo-org/plugin-McpServer/issues>
- Forum: <https://forum.matomo.org>
- Source: <https://github.com/matomo-org/plugin-McpServer>

## License

GPL v3 or later
