# MCP AI Smoke Harness

This harness powers `.github/workflows/mcp-ai-smoke.yml`.

## Stages

1. `setup-matomo-omnifixture.sh`
- Imports `tests/resources/OmniFixture-dump.sql` into CI MySQL.
- Writes Matomo config with empty DB prefix (matching OmniFixture tables).
- Enables MCP tool-call logging.
- Starts a local PHP server.
- Creates a superuser app token and discovers fixture IDs used by prompts.
- Emits `skip_cases` in state when fixture-backed entities are missing.

2. `run-ai-smoke.sh`
- Executes one provider prompt per configured tool case.
- Uses pass/fail evidence from Matomo MCP call logs.
- Supports per-case timeout via `CASE_TIMEOUT_SECONDS` (default `120`).
- Additional MCP tool calls are accepted if the expected tool succeeds at least once.
- Handles empty `cases.json` safely and emits an empty `results.json`.
- Fails the provider run when a configured case fails or is skipped.

3. `run-codex-smoke.sh`
- Thin wrappers that select the provider-specific runtime setup before invoking the shared harness.

## Files

- `cases.json`: prototype smoke cases (`site_get`, `site_list`, `report_processed`).
- `.state.json`: runtime discovery state from setup, including optional `skip_cases`.
- `prompts/*.txt`: prompt templates used by configured cases.
- `artifacts/<provider>/`: generated at runtime in CI (`transcripts`, `logs`, `results`, `results.json`).

## Notes

- Codex uses a temporary CI-local home/config outside the uploaded artifact tree and registers the Matomo MCP endpoint there at runtime.
- Provider login state and runtime configuration stay outside uploaded artifacts.
- The workflow can run providers independently based on secret availability (`OPENAI_APIKEY`).
- The workflow is intentionally report-first/non-blocking for internal prototype usage.
- The workflow summary reads per-provider `artifacts/<provider>/results.json` files.
