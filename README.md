# Matomo MCP Server Plugin

## Description

The MCP Server for Matomo connects your Matomo analytics data to AI tools and large language models (LLMs), including ChatGPT, Claude, and custom AI assistants.

Instead of navigating through reports, you can ask questions in plain English and receive answers based on your analytics data.

### What does it do?

The plugin acts as a bridge between Matomo and AI tools. Once installed, it allows AI assistants to:

* Access your Matomo data
* Answer questions about your website's performance
* Generate insights, summaries, and reports automatically

Think of it as giving your analytics a natural-language interface.

### What can you use it for?

Here are a few examples of what you can do.

#### Ask questions and get instant answers

* "What were my top traffic sources last week?"
* "Which campaigns drove the most conversions?"
* "How is mobile traffic trending this month?"

#### Generate reports in seconds

* Create weekly or monthly summaries
* Produce marketing performance overviews
* Generate executive-ready insights without manual reporting

#### Build smarter workflows

* Connect Matomo to internal AI tools
* Power dashboards with AI-generated insights
* Allow teams to explore data without analytics expertise

### Go beyond insights and take action with AI

When enabled, the MCP Server can also perform actions in Matomo. For example, your AI tools can:

* Create and update segments
* Automate repetitive analytics tasks
* Integrate Matomo into internal workflows

All actions are controlled by your Matomo permissions and MCP configuration.

### Why install this plugin?

* Save time by reducing manual report building
* Make analytics data accessible to people without specialist training
* Bring your analytics into modern AI workflows
* Generate useful insights through natural-language questions

### How do I set up the plugin?

1. Install the plugin in Matomo.
2. Activate **McpServer** under **Administration → Plugins**.
3. Enable MCP under **Administration → System → General Settings → McpServer**.
4. Configure your MCP client with the endpoint and one of the following authentication methods:

   * **OAuth 2.0:** Recommended when your MCP client supports it and the Matomo OAuth2 plugin is installed, enabled, and configured with an OAuth client.
   * **Bearer token:** Use a Matomo `token_auth` value as a Bearer token when OAuth 2.0 is unavailable.

For the recommended end-user setup process, use the connection guide under **Administration → Platform → MCP Server**.

### Security and access model

* MCP access is disabled by default.
* Raw Matomo API discovery and execution tools are disabled separately by default and must be enabled by an administrator.
* The plugin uses Matomo authentication. OAuth 2.0 is available when the OAuth2 plugin is installed, enabled, and configured for the MCP client. Otherwise, a Matomo `token_auth` value can be used as a Bearer token.
* Data access is limited to the sites and reports that the authenticated Matomo user can already access.
* Access can be restricted by permission, role, and API method type.
* Administrators can restrict MCP usage according to the user's or token's privilege level.
* When raw API access is enabled, MCP clients can access the same Matomo API methods available to the authenticated user. This may include methods that change data when an administrator has permitted them.
* When features such as the Visitor Log are available to the authenticated user, MCP clients may be able to access the same underlying data.
* Review your privacy, security, and compliance requirements before enabling raw API access.

### Additional documentation

See the FAQ for technical documentation covering:

* Endpoint details
* Configuration
* Authentication
* MCP enablement
* Raw API access
* Supported capabilities
* Troubleshooting

## Support

* [Report an issue](https://github.com/matomo-org/plugin-McpServer/issues)
* [Visit the Matomo forum](https://forum.matomo.org)
* [View the source code](https://github.com/matomo-org/plugin-McpServer)

## License

GPL v3 or later

