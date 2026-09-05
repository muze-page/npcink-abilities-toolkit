#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="${PLUGIN_SLUG:-npcink-abilities-toolkit}"
PACKAGE_REF="${PACKAGE_REF:-WORKTREE}"
WP_CLI_BIN="${WP_CLI:-wp}"
WP_CLI_PHP="${WP_CLI_PHP:-php}"
WP_CLI_ERROR_REPORTING="${WP_CLI_ERROR_REPORTING:-}"
WP_CLI_MYSQL_SOCKET="${WP_CLI_MYSQL_SOCKET:-}"
DISTIGNORE_FILE="$ROOT_DIR/.distignore"

if [[ "$WP_CLI_BIN" != *.phar ]] && ! command -v "$WP_CLI_BIN" >/dev/null 2>&1; then
	echo "WP-CLI was not found. Set WP_CLI to a wp-cli.phar path or install a global wp command." >&2
	exit 127
fi

tmpdir="$(mktemp -d)"
cleanup() {
	rm -rf "$tmpdir"
}
trap cleanup EXIT

package_dir="$tmpdir/$PLUGIN_SLUG"
excluded_paths=()
while IFS= read -r excluded_path; do
	excluded_paths+=("$excluded_path")
done < <(sed -e 's/\r$//' "$DISTIGNORE_FILE" | awk 'NF && $1 !~ /^#/')

if [[ "WORKTREE" == "$PACKAGE_REF" ]]; then
	mkdir -p "$package_dir"
	rsync_args=("-a")
	for excluded_path in "${excluded_paths[@]}"; do
		rsync_args+=("--exclude=$excluded_path")
	done
	rsync_args+=("$ROOT_DIR/" "$package_dir/")
	rsync "${rsync_args[@]}"
else
	(
		cd "$ROOT_DIR"
		git archive --format=tar --prefix="$PLUGIN_SLUG/" "$PACKAGE_REF" | tar -x -C "$tmpdir"
	)
	shopt -s nullglob dotglob
	for excluded_path in "${excluded_paths[@]}"; do
		if [[ "$excluded_path" == *[\*\?\[]* ]]; then
			matches=("$package_dir"/$excluded_path)
			if [[ "${#matches[@]}" -gt 0 ]]; then
				rm -rf -- "${matches[@]}"
			fi
		else
			rm -rf -- "$package_dir/$excluded_path"
		fi
	done
	shopt -u nullglob dotglob
fi

shopt -s nullglob dotglob
for excluded_path in "${excluded_paths[@]}"; do
	if [[ "$excluded_path" == *[\*\?\[]* ]]; then
		matches=("$package_dir"/$excluded_path)
		if [[ "${#matches[@]}" -eq 0 ]]; then
			continue
		fi
	elif [[ ! -e "$package_dir/$excluded_path" ]]; then
		continue
	fi

		echo "Packaged plugin includes excluded path: $excluded_path" >&2
		exit 1
done
shopt -u nullglob dotglob

wp_args=(plugin check "$package_dir" --mode=update --format=strict-json)
if [[ -n "${WP_PATH:-}" ]]; then
	wp_args=(--path="$WP_PATH" "${wp_args[@]}")
fi

php_args=()
if [[ -n "$WP_CLI_ERROR_REPORTING" ]]; then
	php_args+=("-d" "error_reporting=$WP_CLI_ERROR_REPORTING")
fi
if [[ -n "$WP_CLI_MYSQL_SOCKET" ]]; then
	php_args+=("-d" "mysqli.default_socket=$WP_CLI_MYSQL_SOCKET")
	php_args+=("-d" "pdo_mysql.default_socket=$WP_CLI_MYSQL_SOCKET")
fi

wp_cli_command="$WP_CLI_BIN"
if [[ "$WP_CLI_BIN" != */* ]] && command -v "$WP_CLI_BIN" >/dev/null 2>&1; then
	wp_cli_command="$(command -v "$WP_CLI_BIN")"
fi

if [[ "$WP_CLI_BIN" == *.phar ]] || [[ "${#php_args[@]}" -gt 0 ]]; then
	php_args+=("$wp_cli_command")
	output="$("$WP_CLI_PHP" "${php_args[@]}" "${wp_args[@]}")"
else
	output="$("$wp_cli_command" "${wp_args[@]}")"
fi

printf '%s\n' "$output"

if grep -q '"type":"ERROR"' <<< "$output"; then
	echo "Plugin Check found errors in packaged plugin." >&2
	exit 1
fi

echo "Packaged Plugin Check passed without errors for $PLUGIN_SLUG ($PACKAGE_REF)."
