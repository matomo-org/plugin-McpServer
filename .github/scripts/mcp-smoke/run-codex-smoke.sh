#!/usr/bin/env bash
set -euo pipefail

export SMOKE_PROVIDER=codex
"$(dirname "$0")/run-ai-smoke.sh"
