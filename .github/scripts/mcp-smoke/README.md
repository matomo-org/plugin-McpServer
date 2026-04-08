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

3. `run-codex-smoke.sh` / `run-claude-smoke.sh`
- Thin wrappers that select the provider-specific runtime setup before invoking the shared harness.

4. `.github/actions/setup-mcp-smoke-env`
- Composite action that performs the shared CI bootstrap used by each provider job.
- Sets up PHP/Node, checks out `github-action-tests`, checks out Matomo, installs Composer deps, and runs OmniFixture setup.

## Files

- `cases.json`: prototype smoke cases (`site_get`, `site_list`, `report_processed`), their expected log evidence, and the source for Claude's allowed MCP tool list.
- `.state.json`: runtime discovery state from setup, including optional `skip_cases`.
- `prompts/*.txt`: prompt templates used by configured cases.
- `artifacts/<provider>/`: generated at runtime inside each provider job (`transcripts`, `logs`, `results`, `results.json`).
- `artifacts/<provider>/logs/php-server.log`: PHP built-in server log snapshot for the provider run.

## Notes

- Codex uses a temporary CI-local home/config outside the uploaded artifact tree and registers the Matomo MCP endpoint there at runtime.
- Claude uses a temporary CI-local home/config outside the uploaded artifact tree and loads the Matomo MCP endpoint via a generated HTTP MCP config file at runtime.
- All provider CLIs execute from an empty provider-local temp workdir instead of the repository checkout.
- Provider login state and runtime configuration stay outside uploaded artifacts.
- The workflow can run providers independently based on secret availability (`OPENAI_APIKEY`, `CLAUDE_APIKEY`).
- Provider CLI packages are intentionally installed without version pins so the smoke checks monitor compatibility with the latest released tooling, including changes to MCP schema interpretation and tool invocation. A provider CLI regression is therefore expected to fail this workflow.
- Claude defaults to the generic `sonnet` alias, with `claude_model` still available in `workflow_dispatch` for pinned-model overrides.
- Codex and Claude run in separate CI jobs with isolated Matomo environments and log files.
- Provider jobs share a local composite action for the common Matomo/bootstrap sequence.
- The workflow is intentionally report-first/non-blocking for internal prototype usage.
- The workflow uploads one artifact per provider job and builds a combined summary from the downloaded per-provider `results.json` files.
