#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/npcink-plugin-package-check.XXXXXX")"
cleanup() {
	rm -rf "$TEST_DIR"
}
trap cleanup EXIT

FAKE_WP="$TEST_DIR/wp"
CALL_LOG="$TEST_DIR/calls.log"
cat > "$FAKE_WP" <<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$PLUGIN_CHECK_TEST_LOG"
printf '%s\n' "${PLUGIN_CHECK_TEST_OUTPUT:-[]}" 
BASH
chmod +x "$FAKE_WP"

unset WP_PATH WP_CLI_PHP WP_CLI_ERROR_REPORTING WP_CLI_MYSQL_SOCKET WP_DB_SOCKET
PLUGIN_CHECK_TEST_LOG="$CALL_LOG" \
PLUGIN_CHECK_TEST_OUTPUT='[{"type":"WARNING","code":"test_warning"}]' \
WP_CLI="$FAKE_WP" \
bash "$ROOT_DIR/scripts/check-plugin-package.sh" >/dev/null

if grep -q -- '--path=' "$CALL_LOG"; then
	echo "Packaged Plugin Check unexpectedly required a WordPress path." >&2
	exit 1
fi
if ! grep -Eq '^plugin check .*/npcink-abilities-toolkit --mode=update --format=strict-json$' "$CALL_LOG"; then
	echo "Packaged Plugin Check did not invoke the standalone update-mode command." >&2
	exit 1
fi

if PLUGIN_CHECK_TEST_LOG="$CALL_LOG" \
	PLUGIN_CHECK_TEST_OUTPUT='[{"type":"ERROR","code":"test_error"}]' \
	WP_CLI="$FAKE_WP" \
	bash "$ROOT_DIR/scripts/check-plugin-package.sh" >/dev/null 2>&1; then
	echo "Packaged Plugin Check did not fail when Plugin Check returned an error." >&2
	exit 1
fi

echo "OK: packaged Plugin Check works without WP_PATH and rejects errors"
