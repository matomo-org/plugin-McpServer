# AGENTS.md

## Purpose

This file is the agent-facing guide for the `McpServer` Matomo plugin.

Use it to discover the codebase quickly, make targeted changes, and extend the plugin without breaking existing MCP behavior or drifting from the current structure.

This document is repo-specific. Prefer it over generic Matomo assumptions when working in this repository.

## Scope And Defaults

- Optimize for small, targeted changes.
- Preserve existing structure and naming unless a refactor is explicitly requested.
- Treat checked-in `vendor/` code as read-only unless the task is explicitly about dependency or vendor refresh.
- Do not duplicate README setup/product documentation unless the change requires agent execution context.
- Treat `README.md` as public-facing documentation. Keep the `## Description` section suitable for Matomo Marketplace display, and keep repository-structure or agent workflow guidance in `AGENTS.md` instead.

## Repository Map

### Plugin entrypoints and metadata

- `API.php`: main MCP API endpoint, request validation flow, auth/error handling, and hand-off into the server.
- `McpServer.php`: plugin lifecycle hooks, stylesheet registration, install/uninstall table setup.
- `McpServerFactory.php`: server/container wiring and MCP server construction.
- `SystemSettings.php`: plugin settings, including MCP enablement.
- `plugin.json`, `composer.json`: plugin metadata and PHP dependency constraints.

### MCP capability surface

- `McpTools/`: user-facing MCP tools. Each tool is a class extending `Contracts\McpTool` with an `init()` that declares name/description/annotations/schemas and a public `execute(...)` method whose typed parameters define the input shape. Start here for new capabilities or changes to tool behavior.
- `Schemas/`: tool input/output schemas grouped by domain (`Api`, `Sites`, `Reports`, `Goals`, `Segments`, `Dimensions`). Most domains expose a single combined `*ToolSchemas` class; `Api` and `Reports` still keep per-tool schema classes where the shape diverges between tools.
- `Contracts/`: Matomo-owned types that bound the plugin's tool surface. Includes the `McpTool` base class, `McpToolAnnotations`, `McpToolIcon`, and `McpToolCallException`, plus shared typed records and ports under `Records/` and `Ports/`. Tool classes interact with these types only — they must not import the vendored MCP SDK directly.

### Domain and infrastructure layers

- `Services/`: domain/query logic and Matomo-facing gateways, grouped by feature area.
- `Support/Api/`: endpoint boundary helpers, request-id extraction, JSON-RPC error responses, and MCP endpoint rules.
- `Support/`: shared pagination, normalization, logging, tooling helpers, and error mapping.
- `Server/`: MCP server handler support.
- `Session/`: MCP session persistence and session table management.

### Verification and maintenance

- `tests/Integration/`: primary coverage for externally visible behavior and cross-layer plugin behavior.
- `tests/Unit/`: focused unit coverage for isolated logic.
- `tests/Framework/`: shared test helpers and contract assertions.
- `.github/workflows/`: CI expectations for plugin tests, PHPCS, PHPStan, and checklist gates.
- `phpcs.xml`, `phpstan.neon`: local code-quality configuration for this plugin.

## Where To Start

### If the task touches endpoint behavior, auth, or request boundaries

Start with `API.php` and `Support/Api/`. Follow the current request flow before editing:

1. request format and endpoint validation
2. request parsing / JSON-RPC metadata extraction
3. auth and access checks
4. MCP enabled/disabled behavior
5. server transport hand-off

Check existing integration tests before changing any of these behaviors. Start with `tests/Integration/McpApiEndpointBoundaryTest.php`.

### If the task adds or changes an MCP tool

Start in `McpTools/`, then trace the matching implementation in `Services/` and `Schemas/`.

Preferred flow:

1. tool class defines the user-facing capability
2. service or gateway code fetches/transforms Matomo data
3. schema defines the output contract
4. tests cover the visible behavior

### If the task changes domain behavior

Find the domain area under `Services/Api`, `Services/Sites`, `Services/Reports`, `Services/Goals`, `Services/Segments`, `Services/Dimensions`, or `Services/System`.

Before editing:

- find the integration tests that currently cover the behavior
- check for matching records or ports in `Contracts/`
- check for supporting pagination, normalization, or error helpers in `Support/`

## Extension Rules

### Adding new MCP capabilities

- Add user-facing capability through a tool class in `McpTools/` that extends `Contracts\McpTool`. Implement `init()` to set name, description, annotations, input schema, and (when applicable) title, output schema, icons, and meta. Declare a public `execute(...)` method whose typed parameters define the JSON-RPC input shape.
- Register the new tool class in `McpServerFactory::BUILTIN_TOOL_CLASSES` so the factory resolves it from the container.
- Abort tool execution via `$this->fail($message)` from `execute()`; downstream services and helpers in the call chain may throw `McpToolCallException` directly when surfacing client-facing failures.
- Interact only with Matomo-owned tool types (`McpTool`, `McpToolAnnotations`, `McpToolIcon`, `McpToolCallException`) inside `McpTools/`. Do not import or expose vendored MCP SDK types from tool classes — translation to the SDK happens centrally in `McpServerFactory`.
- Keep Matomo/core access in focused services or gateway classes under `Services/`.
- Define or update tool schemas in `Schemas/`, matching the domain layout.
- Use `Contracts/Records` and `Contracts/Ports` only when a shared typed record or service boundary improves clarity.

### Keeping boundaries clean

- Do not move Matomo access logic into tool classes when it belongs in services.
- Do not bypass existing support helpers if pagination, normalization, logging, or error mapping already has a home.
- Prefer matching existing domain grouping and naming patterns over inventing a new layout for a small feature.

### Testing expectations for new behavior

- Cover externally visible behavior with integration tests first.
- Add unit tests for isolated logic where they provide fast, focused regression coverage.
- Do not introduce new public behavior without matching tests.

## Verification Expectations

### For code changes

Treat all of the following as the default verification bar:

- run the full plugin test suite
- run targeted tests for the touched behavior when focused coverage exists
- run PHPStan with this plugin's configuration
- run PHPCS with this plugin's rules

The full plugin suite is required for code changes to reduce the chance of breaking unrelated MCP tools or shared plugin behavior.

### For docs-only changes

Lighter verification is acceptable. Full plugin-suite execution is not required for product or README copy edits unless the documentation change affects engineering instructions that should be validated against the codebase.

## Guardrails

- Prefer small, reviewable changes over cross-cutting rewrites.
- Avoid broad refactors during feature work unless explicitly requested.
- Preserve existing public behavior unless the task explicitly changes it.
- Keep agent guidance and implementation guidance aligned with current repo truth.
- When unsure where code belongs, choose the existing nearest feature area instead of creating a new abstraction layer by default.
