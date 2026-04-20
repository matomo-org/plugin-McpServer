# Matomo MCP Server Plugin


## Description

The MCP Server for Matomo lets you connect your Matomo analytics data to AI tools and large language models (LLMs) like ChatGPT, Claude, or custom AI assistants.

Instead of digging through reports, you can simply ask questions in plain English and get answers based on your real analytics data.

### What does it do?

This plugin acts as a bridge between Matomo and AI tools. Once installed, it allows AI assistants to:

* Access your Matomo data
* Answer questions about your website performance
* Generate insights, summaries, and reports automatically

Think of it as giving your analytics a natural language interface.

### What can you use it for?

Here are a few examples of what you can do:

#### Ask questions, get instant answers
* “What were my top traffic sources last week?”
* “Which campaigns drove the most conversions?”
* “How is mobile traffic trending this month?”

#### Generate reports in seconds
* Weekly or monthly summaries
* Marketing performance overviews
* Executive-ready insights without manual work

#### Build smarter workflows
* Connect Matomo to internal AI tools
* Power dashboards with AI-generated insights
* Enable teams to explore data without needing analytics expertise

### Go beyond insights: take action with AI (optional)

If you choose to enable it, the MCP Server can also perform actions in Matomo. This means your AI tools can for example:

* Create and update segments
* Automate repetitive analytics tasks
* Integrate Matomo into internal workflows

All actions are controlled by your permissions and configuration.

### Why install this plugin?
* Save time – no more manual report building
* Make data accessible – anyone can ask questions, no training needed
* Unlock AI use cases – bring your analytics into modern AI workflows

### How do I set this plugin up?

* Install the plugin in Matomo.
* Activate **McpServer** in **Administration -> Plugins**.
* Enable MCP in **Administration -> System -> General Settings -> McpServer**.
* Configure your MCP client with the endpoint and one of these authentication methods:
  * OAuth2, if your MCP client supports it and the Matomo `OAuth2` plugin is installed and enabled, with an OAuth2 client configured there.
  * A Matomo `token_auth` used as a Bearer token otherwise.

For the recommended end-user setup flow, use the in-product connect guide at **Administration -> Platform -> MCP Server**.

### Security And Access Model

- MCP access is disabled by default.
- Raw Matomo API discovery and execution tools are separately disabled by default and must be enabled by an administrator.
- The plugin uses Matomo authentication, including OAuth2 when the Matomo `OAuth2` plugin is installed and enabled and an OAuth2 client is configured for the MCP client, or `token_auth` Bearer tokens otherwise.
- Data access is limited to the same sites and reports the Matomo user can already access.
- Data access can be limited to specific permissions/roles and what type of methods can be accessed.
- Administrators can optionally restrict MCP usage to users or tokens at or below a configured privilege level.
- When raw API access is enabled, MCP clients can access the same Matomo API surface available to the authenticated user, including state-changing methods if an administrator has allowed them.
- If features such as the Visitor Log are available to that user, MCP clients may access the same underlying data scope.
- Review privacy, security, and compliance requirements before enabling raw API access.

### Additional Documentation

The FAQ includes additional technical documentation for endpoint details, configuration, MCP enablement behavior, raw API access guidance, supported capabilities, and troubleshooting.

## Support

- Issues: <https://github.com/matomo-org/plugin-McpServer/issues>
- Forum: <https://forum.matomo.org>
- Source: <https://github.com/matomo-org/plugin-McpServer>

## License

GPL v3 or later
