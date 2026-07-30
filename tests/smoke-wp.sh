#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_CLI_BIN="${WP_CLI:-wp}"
WP_CLI_PHP="${WP_CLI_PHP:-php}"
WP_CLI_PHP_ARGS="${WP_CLI_PHP_ARGS:-}"
WP_CLI_ERROR_REPORTING="${WP_CLI_ERROR_REPORTING:-}"
WP_CLI_MYSQL_SOCKET="${WP_CLI_MYSQL_SOCKET:-}"
PLUGIN_SLUG="${PLUGIN_SLUG:-npcink-abilities-toolkit}"

if [[ "$WP_CLI_BIN" != *.phar ]] && ! command -v "$WP_CLI_BIN" >/dev/null 2>&1; then
	cat >&2 <<'EOF'
WP-CLI was not found.

Set WP_CLI to a wp-cli.phar path, or install a global `wp` command.
For the shared Local site, use:

WP_CLI=/tmp/wp-cli.phar \
WP_CLI_PHP=/opt/homebrew/bin/php \
WP_CLI_ERROR_REPORTING=8191 \
WP_CLI_MYSQL_SOCKET="/Users/muze/Library/Application Support/Local/run/NPb24Zg9g/mysql/mysqld.sock" \
WP_PATH="/Users/muze/Local Sites/magick-ai/app/public" \
composer smoke:wp
EOF
	exit 127
fi

wp_args=()
if [[ -n "${WP_PATH:-}" ]]; then
	wp_args+=(--path="$WP_PATH")
fi

run_wp() {
	php_args=()
	if [[ -n "$WP_CLI_ERROR_REPORTING" ]]; then
		php_args+=("-d" "error_reporting=$WP_CLI_ERROR_REPORTING")
	fi
	if [[ -n "$WP_CLI_MYSQL_SOCKET" ]]; then
		php_args+=("-d" "mysqli.default_socket=$WP_CLI_MYSQL_SOCKET")
		php_args+=("-d" "pdo_mysql.default_socket=$WP_CLI_MYSQL_SOCKET")
	fi
	if [[ -n "$WP_CLI_PHP_ARGS" ]]; then
		extra_php_args=()
		read -r -a extra_php_args <<< "$WP_CLI_PHP_ARGS"
		php_args+=("${extra_php_args[@]}")
	fi

	wp_cli_command="$WP_CLI_BIN"
	if [[ "$WP_CLI_BIN" != */* ]] && command -v "$WP_CLI_BIN" >/dev/null 2>&1; then
		wp_cli_command="$(command -v "$WP_CLI_BIN")"
	fi

	if [[ "$WP_CLI_BIN" == *.phar ]] || [[ "${#php_args[@]}" -gt 0 ]]; then
		if [[ ${#wp_args[@]} -gt 0 ]]; then
			"$WP_CLI_PHP" "${php_args[@]}" "$wp_cli_command" "${wp_args[@]}" "$@"
		else
			"$WP_CLI_PHP" "${php_args[@]}" "$wp_cli_command" "$@"
		fi
		return
	fi

	if [[ ${#wp_args[@]} -gt 0 ]]; then
		"$wp_cli_command" "${wp_args[@]}" "$@"
	else
		"$wp_cli_command" "$@"
	fi
}

plugin_activated_by_smoke=false
light_profile_mu_plugin=""
cleanup_smoke_state() {
	if [[ -n "$light_profile_mu_plugin" ]]; then
		rm -f "$light_profile_mu_plugin"
		light_profile_mu_plugin=""
	fi
	if [[ "$plugin_activated_by_smoke" == "true" ]]; then
		if ! run_wp plugin deactivate "$PLUGIN_SLUG" >/dev/null; then
			echo "Unable to restore the plugin's inactive state after smoke." >&2
		fi
		plugin_activated_by_smoke=false
	fi
}
trap cleanup_smoke_state EXIT

run_wp core is-installed >/dev/null
if ! run_wp plugin is-active "$PLUGIN_SLUG" >/dev/null 2>&1; then
	run_wp plugin activate "$PLUGIN_SLUG" >/dev/null
	plugin_activated_by_smoke=true
fi
NPCINK_ABILITIES_TOOLKIT_SMOKE_PROFILE=default run_wp eval-file "$ROOT_DIR/tests/smoke-wp.php"

mu_plugin_dir="$(run_wp eval 'echo WPMU_PLUGIN_DIR;' 2>/dev/null | grep '^/' | tail -n 1 || true)"
if [[ -z "$mu_plugin_dir" ]]; then
	echo "Unable to resolve WPMU_PLUGIN_DIR for light profile smoke." >&2
	exit 1
fi
mkdir -p "$mu_plugin_dir"
light_profile_mu_plugin="$(mktemp "$mu_plugin_dir/npcink-abilities-toolkit-light-profile-smoke.XXXXXX.php")"
cat > "$light_profile_mu_plugin" <<'PHP'
<?php
add_filter(
	'npcink_abilities_toolkit_enabled_packages',
	static function ( $packages ) {
		$packages['core_read']             = true;
		$packages['core_write']            = false;
		$packages['core_destructive']      = false;
		$packages['core_comment']          = false;
		$packages['npcink_catalog_bridge'] = false;
		$packages['admin_test_page']       = false;
		$packages['read_cache_hooks']      = false;

		return $packages;
	}
);
add_filter(
	'npcink_abilities_toolkit_enabled_read_packs',
	static function () {
		return array( 'core_wordpress_read' );
	}
);
PHP
NPCINK_ABILITIES_TOOLKIT_SMOKE_PROFILE=light_core_read run_wp eval-file "$ROOT_DIR/tests/smoke-wp.php"
