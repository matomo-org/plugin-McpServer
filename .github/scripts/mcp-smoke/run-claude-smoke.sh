#!/usr/bin/env bash
set -euo pipefail

export SMOKE_PROVIDER=claude
"$(dirname "$0")/run-ai-smoke.sh"
