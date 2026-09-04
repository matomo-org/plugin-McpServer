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
- `McpToolsProvider.php`: resolves the built-in tool set and fires the `McpServer.addTools` / `McpServer.filterTools` events so other plugins can register or restrict MCP tools.
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
- `Support/Normalization/`: two unrelated things. Intake normalization rewrites equivalent tool arguments to one canonical form before schema validation - `ToolIntakeProfiles` is the closed per-tool allowlist of what each tool recovers, and `IntakeNormalizer` applies one profile. `ToolDataNormalizer` is not part of it: it asserts the shape of untyped array data already in hand, both arguments a tool class received and rows a gateway got back from Matomo core, and raises `McpToolCallException` instead of rewriting anything.
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
- Register the new tool class in `McpToolsProvider::BUILTIN_TOOL_CLASSES` so the provider resolves it from the container.
- Abort tool execution via `$this->fail($message)` from `execute()`; downstream services and helpers in the call chain may throw `McpToolCallException` directly when surfacing client-facing failures.
- Interact only with Matomo-owned tool types (`McpTool`, `McpToolAnnotations`, `McpToolIcon`, `McpToolCallException`) inside `McpTools/`. Do not import or expose vendored MCP SDK types from tool classes — translation to the SDK happens centrally in `McpServerFactory`.
- Keep Matomo/core access in focused services or gateway classes under `Services/`.
- Define or update tool schemas in `Schemas/`, matching the domain layout.
- Use `Contracts/Records` and `Contracts/Ports` only when a shared typed record or service boundary improves clarity.

### Widening or changing accepted tool input

- Recovery of an alternate argument representation belongs in `Support/Normalization/ToolIntakeProfiles.php`, never in a tool class or a service. That registry is the single allowlist: a tool absent from it is not normalized, and a representation absent from a listed profile stays invalid, so every widening is a visible edit to one file. It keys on the tool class, not the tool name, so a listener that displaces a built-in name through `McpServer.addTools` is never normalized against a schema it does not publish.
- Tools a model uses in sequence need the same selector recovery. A spelling that only the executing tool recovers makes the paired discovery tool reject input execution would have run, which reads to the model as the two tools disagreeing. Both pairs are registered that way: `matomo_report_metadata` and `matomo_report_processed` share individual-selector canonicalisation through `ReportSelectorConvergence`, and `matomo_api_get` shares redundant-selector convergence through `ApiSelectorConvergence` with the five `matomo_api_call_*` tools (which share one whole profile, since they also share an input schema). Share the convergence, not necessarily the profile - a discovery tool that publishes no parameter object must not register object fields for one.
- Keep the normalization engine free of Matomo domain knowledge. It moves what a profile registers; report names, date grammar, segment syntax, and value bounds are decided by the schema and the services afterwards. It does not retype either: a stringified integer is promoted for validation by `CompatibleCallToolHandler::coerceIntegerStringsForValidation()` and cast on dispatch by the SDK's `ReferenceHandler`, for every tool rather than only profiled ones, so a profile that restated the conversion would add a third rule to keep in step with those two. Comparing two locations still folds interchangeable spellings, because that judgement is made in the engine - see `IntakeNormalizer::areEquivalent()`.
- Normalization runs before schema validation and its result is what gets validated and dispatched. Never use it to accept a value the tool's published input schema would reject.
- A recovery is silent, so it must be unambiguous. Register a representation only when it can mean exactly one thing; anything needing a guess between two readings belongs in a rejection, not a rewrite.
- A convergence may decline to normalize a form, but must never reject one the downstream lookup resolves. Declining is safe because a more capable lookup runs afterwards; rejecting is not, because it tells the caller two selectors contradict each other when they name one report. Report unique IDs do not encode an authoritative module/action boundary, so `ReportSelectorConvergence` leaves every `reportUniqueId` plus `apiModule`/`apiAction` combination for the canonical schema to reject instead of guessing whether the selectors agree.
- Every `ArgumentIssueException` surfaces as an `isError` tool result, while schema failures stay JSON-RPC `-32602`. The split is by which layer rejected, not by how malformed the input is: normalization rejects only what a model can act on from the message, so it answers on the channel a model reads. That covers contradictions between two locations, but also a `method` outside the `Module.action` form, a selector past `SelectorConvergenceInterface::MAX_SELECTOR_LENGTH`, and a parameter object string that opens as an object but does not decode. Preserve the split so clients branching on `-32602` keep seeing it for schema failures and models still see what they can fix. Enumerate every reason on both channels in `docs/faq.md`, not just the ones a given change touched - a reason missing from that list is one nobody can tell apart from a bug.
- Paginated tools reject an out-of-range limit rather than clamping it, and every one of them declares the bound in its input schema so a client can read it without guessing. `matomo_report_processed`, the `*List` tools and `matomo_site_search` all carry an explicit `maximum`, and `CursorPaginator::paginate()` rejects the same value again behind it. Keep a new paginated tool on that convention: a tool that silently served fewer rows than the caller asked for would disagree with its siblings, and a model that learned the looser behaviour from one tool would carry it to the rest. The service-side clamps are defensive backstops behind the schema bound, not the advertised behaviour.
- Guidance about a value belongs to the layer that understands it. A segment Matomo cannot parse is recognised in `ReportProcessedQueryService`, not in intake normalization, because segment grammar is domain knowledge the engine deliberately does not carry - see the bullet above on keeping the engine free of it. Service-layer guidance follows intake's rule anyway: it names the argument at fault and never repeats the value, which is what lets a segment expression or a pasted token stay out of the reply.

### Keeping boundaries clean

- Do not move Matomo access logic into tool classes when it belongs in services.
- Do not bypass existing support helpers if pagination, normalization, logging, or error mapping already has a home.
- Prefer matching existing domain grouping and naming patterns over inventing a new layout for a small feature.

### Serving both protocol eras

The endpoint answers two MCP lifecycles from one URL, and the SDK's transport decides per request which one serves it:

- The handshake era (`2024-11-05` through `2025-11-25`) negotiates a protocol version at `initialize` and carries a session, persisted through `Session/DbSessionStore.php`, across later requests.
- The modern era (`2026-07-28`) has neither. Each request declares its own protocol version, client identity and client capabilities in `params._meta`, mirrors them onto the standard request headers the server validates against, and is served with no session at all.

What this means when changing server behavior:

- Assume neither an `initialize` handshake nor a session. Anything a request needs must be derivable from that request. The session object a modern-era handler receives is a throwaway the SDK creates per request; treat its id as an implementation detail, never as a client identity.
- Request handlers are the only layer both eras traverse, so behavior that must hold for both belongs in a handler (or a decorator around one), not on the SDK event seam and not in transport middleware.
- Transport middleware pinned in `McpServerFactory::createTransport()` runs before the era is known, so only rules true of both eras belong there. Anything era-specific is the transport's own business — see the method's docblock for what that means for the protocol-version header rule.
- `server/discover` and `subscriptions/listen` are answered inside the SDK, ahead of any registered handler. They cannot be decorated, declined, or observed; the only lever is builder configuration.
- Re-check the pinned middleware list, and this section, when bumping `mcp/sdk`. Both encode where the SDK draws the line between the eras, and 0.8 moved it once already.

### Extending MCP protocol handling

- Publish observability from whichever layer both eras reach. Tool activity is published by the decorators in `Server/Handler/Request/Published*Handler.php`; `Server/ServerEventBridge.php` covers what only the handshake era has (the `initialize` lifecycle and the generic fallback) and deliberately skips `tools/call` and `tools/list` so those are not published twice. A new event for a method both eras serve belongs in a handler decorator, not in the bridge.
- Every completed MCP request and every received MCP notification the plugin can observe must publish exactly one plugin-owned event through `McpServer.serverEvent`; explicit transport lifecycle signals such as session termination must be bridged at their nearest reliable boundary.
- When adding support for a request, notification, or transport lifecycle signal, update the publishing layer and its integration tests in the same change. Preserve the generic `McpServerEvent` fallback for methods without a richer contract, and add a dedicated event subclass only when method-specific data is useful to subscribers.
- Publishing must never disrupt the MCP response: guard every publish and log the failure at debug level, the way the bridge and the decorators already do.
- Keep vendored SDK event and schema types inside the bridge, the handler decorators, or other infrastructure adapters. Public event payloads must use types under `Contracts/Events` so subscribers never depend on the bundled SDK.

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
