#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/npcink-smoke-lifecycle.XXXXXX")"
cleanup() {
	rm -rf "$TEST_DIR"
}
trap cleanup EXIT

FAKE_WP="$TEST_DIR/wp"
cat > "$FAKE_WP" <<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$*" >> "$SMOKE_TEST_LOG"
case "${1:-} ${2:-}" in
	"core is-installed")
		exit 0
		;;
	"plugin is-active")
		[[ "$(cat "$SMOKE_TEST_STATE")" == "active" ]]
		;;
	"plugin activate")
		printf 'active\n' > "$SMOKE_TEST_STATE"
		;;
	"plugin deactivate")
		printf 'inactive\n' > "$SMOKE_TEST_STATE"
		;;
	"eval echo WPMU_PLUGIN_DIR;")
		printf '%s\n' "$SMOKE_TEST_MU_DIR"
		;;
	"eval-file "*)
		if [[ "${NPCINK_ABILITIES_TOOLKIT_SMOKE_PROFILE:-}" == "light_core_read" ]]; then
			compgen -G "$SMOKE_TEST_MU_DIR/npcink-abilities-toolkit-light-profile-smoke.*.php" >/dev/null
		fi
		;;
	*)
		echo "Unexpected fake WP-CLI call: $*" >&2
		exit 1
		;;
esac
BASH
chmod +x "$FAKE_WP"

run_case() {
	local initial_state="$1"
	local expected_activation_count="$2"
	local expected_deactivation_count="$3"
	local case_dir="$TEST_DIR/$initial_state"
	local state_file="$case_dir/state"
	local log_file="$case_dir/calls.log"
	local mu_dir="$case_dir/mu-plugins"
	local sentinel="$mu_dir/npcink-abilities-toolkit-light-profile-smoke.php"

	mkdir -p "$mu_dir"
	printf '%s\n' "$initial_state" > "$state_file"
	printf 'sentinel\n' > "$sentinel"

	SMOKE_TEST_STATE="$state_file" \
	SMOKE_TEST_LOG="$log_file" \
	SMOKE_TEST_MU_DIR="$mu_dir" \
	WP_CLI="$FAKE_WP" \
	WP_CLI_PHP_ARGS="" \
	WP_CLI_ERROR_REPORTING="" \
	WP_CLI_MYSQL_SOCKET="" \
	WP_PATH="" \
	bash "$ROOT_DIR/tests/smoke-wp.sh"

	[[ "$(cat "$state_file")" == "$initial_state" ]]
	[[ "$(cat "$sentinel")" == "sentinel" ]]
	[[ "$(find "$mu_dir" -type f | wc -l | tr -d ' ')" == "1" ]]
	[[ "$(grep -c '^plugin activate ' "$log_file" || true)" == "$expected_activation_count" ]]
	[[ "$(grep -c '^plugin deactivate ' "$log_file" || true)" == "$expected_deactivation_count" ]]
	[[ "$(grep -c '^eval-file ' "$log_file" || true)" == "2" ]]
}

run_case inactive 1 1
run_case active 0 0

echo "OK: WordPress smoke restores plugin and MU-plugin lifecycle state"
