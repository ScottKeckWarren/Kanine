#!/usr/bin/env bash
#
# bin/smoke-test.sh — Kanine V0.1 end-to-end smoke test
#
# Runs a full supervisor + pup pipeline against a real GitHub repository and a
# real `claude --headless` installation.  This script requires:
#
#   - GITHUB_TOKEN env var set to a valid GitHub personal access token
#   - KANINE_SMOKE_REPO env var set to an "owner/repo" string containing at
#     least one open issue labelled "kanine: ready"
#   - `claude` in $PATH, configured for headless operation
#   - PHP 8.2+ and Composer dependencies installed (`composer install`)
#
# Usage:
#   GITHUB_TOKEN=ghp_xxx KANINE_SMOKE_REPO=owner/repo ./bin/smoke-test.sh
#
# Exit codes:
#   0  all checks passed
#   1  a required precondition is not met or a step failed
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TMP_DIR="${PROJECT_ROOT}/tmp/smoke-$$"
CONFIG_FILE="${TMP_DIR}/kanine.yaml"
SUPERVISOR_LOG="${TMP_DIR}/supervisor.log"
PUP_LOG="${TMP_DIR}/pup.log"
KANINE="${PROJECT_ROOT}/vendor/bin/kanine"
PUP_ID="smoke-pup-$(date +%s)"
SUPERVISOR_PORT=13737
SUPERVISOR_URL="http://127.0.0.1:${SUPERVISOR_PORT}"

cleanup() {
    echo "[smoke] Cleaning up..."
    kill "${SUPERVISOR_PID:-}" 2>/dev/null || true
    kill "${PUP_PID:-}" 2>/dev/null || true
    rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Precondition checks
# ---------------------------------------------------------------------------

echo "[smoke] Checking preconditions..."

if [[ -z "${GITHUB_TOKEN:-}" ]]; then
    echo "ERROR: GITHUB_TOKEN env var is not set. Export it before running this script." >&2
    exit 1
fi

if [[ -z "${KANINE_SMOKE_REPO:-}" ]]; then
    echo "ERROR: KANINE_SMOKE_REPO env var is not set." >&2
    echo "       Set it to an owner/repo string with at least one open issue labelled 'kanine: ready'." >&2
    exit 1
fi

if ! command -v claude &>/dev/null; then
    echo "ERROR: 'claude' not found in PATH. Install Claude Code and ensure 'claude' is on PATH." >&2
    exit 1
fi

if [[ ! -x "${KANINE}" ]]; then
    echo "ERROR: ${KANINE} not found or not executable. Run 'composer install' first." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------------

mkdir -p "${TMP_DIR}"

cat > "${CONFIG_FILE}" <<YAML
github:
  repositories:
    - ${KANINE_SMOKE_REPO}
  token_env: GITHUB_TOKEN
  ready_label: "kanine: ready"

supervisor:
  host: 127.0.0.1
  port: ${SUPERVISOR_PORT}

log_file: ${SUPERVISOR_LOG}
YAML

echo "[smoke] Config written to ${CONFIG_FILE}"

# ---------------------------------------------------------------------------
# Step 1: Start supervisor
# ---------------------------------------------------------------------------

echo "[smoke] Starting supervisor..."

GITHUB_TOKEN="${GITHUB_TOKEN}" "${KANINE}" serve --config="${CONFIG_FILE}" \
    > "${SUPERVISOR_LOG}.stdout" 2>&1 &
SUPERVISOR_PID=$!

echo "[smoke] Supervisor PID=${SUPERVISOR_PID}"

# Wait up to 10 seconds for the supervisor to bind
for i in $(seq 1 20); do
    if curl -sf "${SUPERVISOR_URL}/pups/register" \
        -H 'Content-Type: application/json' \
        -d '{"pup_id":"probe","hostname":"probe"}' &>/dev/null; then
        break
    fi
    sleep 0.5
done

if ! kill -0 "${SUPERVISOR_PID}" 2>/dev/null; then
    echo "ERROR: Supervisor did not start. Log:" >&2
    cat "${SUPERVISOR_LOG}.stdout" >&2
    exit 1
fi

echo "[smoke] Supervisor is up."

# ---------------------------------------------------------------------------
# Step 2: Assert supervisor logged at least one queued issue
# ---------------------------------------------------------------------------

echo "[smoke] Checking supervisor queued at least one issue..."

if ! grep -q 'Queued #' "${SUPERVISOR_LOG}.stdout" 2>/dev/null; then
    echo "WARN: No 'Queued #' line found in supervisor output." >&2
    echo "      Either KANINE_SMOKE_REPO has no 'kanine: ready' issues, or issue loading failed." >&2
    echo "      Supervisor output:" >&2
    cat "${SUPERVISOR_LOG}.stdout" >&2
    exit 1
fi

echo "[smoke] Issue queued."

# ---------------------------------------------------------------------------
# Step 3: Start pup
# ---------------------------------------------------------------------------

echo "[smoke] Starting pup..."

KANINE_SUPERVISOR_URL="${SUPERVISOR_URL}" \
KANINE_PUP_ID="${PUP_ID}" \
GITHUB_TOKEN="${GITHUB_TOKEN}" \
"${KANINE}" pup --pup-id="${PUP_ID}" \
    > "${PUP_LOG}" 2>&1 &
PUP_PID=$!

echo "[smoke] Pup PID=${PUP_PID}"

# ---------------------------------------------------------------------------
# Step 4: Assert registration logged
# ---------------------------------------------------------------------------

echo "[smoke] Waiting for pup to register..."

for i in $(seq 1 20); do
    if grep -q "Registered pup ${PUP_ID}" "${PUP_LOG}" 2>/dev/null || \
       grep -q "Registered pup ${PUP_ID}" "${SUPERVISOR_LOG}.stdout" 2>/dev/null; then
        break
    fi
    sleep 0.5
done

if ! grep -q "${PUP_ID}" "${PUP_LOG}" 2>/dev/null && \
   ! grep -q "${PUP_ID}" "${SUPERVISOR_LOG}.stdout" 2>/dev/null; then
    echo "ERROR: Pup registration not logged within timeout." >&2
    echo "Pup log:" >&2
    cat "${PUP_LOG}" >&2
    exit 1
fi

echo "[smoke] Pup registered."

# ---------------------------------------------------------------------------
# Step 5: Assert task assigned and claude started
# ---------------------------------------------------------------------------

echo "[smoke] Waiting for task assignment and claude start..."

for i in $(seq 1 40); do
    if grep -q 'Starting claude for issue #' "${PUP_LOG}" 2>/dev/null; then
        break
    fi
    sleep 0.5
done

if ! grep -q 'Starting claude for issue #' "${PUP_LOG}" 2>/dev/null; then
    echo "ERROR: Claude start log line not found within timeout." >&2
    echo "Pup log:" >&2
    cat "${PUP_LOG}" >&2
    exit 1
fi

echo "[smoke] Task assigned and claude started."

# ---------------------------------------------------------------------------
# Step 6: Wait for claude to exit and pup to return to idle
# ---------------------------------------------------------------------------

echo "[smoke] Waiting for claude to exit (up to 120 seconds)..."

for i in $(seq 1 240); do
    if grep -q 'Claude exited with code' "${PUP_LOG}" 2>/dev/null; then
        break
    fi
    sleep 0.5
done

if ! grep -q 'Claude exited with code' "${PUP_LOG}" 2>/dev/null; then
    echo "ERROR: Claude did not exit within 120 seconds." >&2
    echo "Pup log:" >&2
    cat "${PUP_LOG}" >&2
    exit 1
fi

echo "[smoke] Claude exited."

# ---------------------------------------------------------------------------
# Step 7: Assert log format is correct
# ---------------------------------------------------------------------------

echo "[smoke] Checking log format..."

if grep -qP '^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] kanine\.\w+: ' "${SUPERVISOR_LOG}.stdout" 2>/dev/null || \
   grep -qP '^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] kanine\.\w+: ' "${PUP_LOG}" 2>/dev/null; then
    echo "[smoke] Log format OK."
else
    echo "WARN: Expected log format not matched. Showing sample lines:" >&2
    head -5 "${SUPERVISOR_LOG}.stdout" >&2
    head -5 "${PUP_LOG}" >&2
fi

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------

echo ""
echo "[smoke] All smoke test checks PASSED."
echo ""
echo "Supervisor log: ${SUPERVISOR_LOG}.stdout"
echo "Pup log:        ${PUP_LOG}"
echo ""
echo "NOTE: Tag v0.1.0 once this script passes in your environment."
