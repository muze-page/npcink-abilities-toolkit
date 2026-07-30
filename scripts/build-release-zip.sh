#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="${PLUGIN_SLUG:-npcink-abilities-toolkit}"
VERSION="${1:-${VERSION:-}}"
DISTIGNORE_FILE="$ROOT_DIR/.distignore"

if [[ -z "$VERSION" ]]; then
	echo "Release candidate version is required." >&2
	echo "Example: VERSION=0.6.0 composer release:zip" >&2
	exit 2
fi

cd "$ROOT_DIR"

source_commit="$(bash "$ROOT_DIR/scripts/check-release-source.sh" "$VERSION" "$ROOT_DIR")"

plugin_version="$(php -r '$s=file_get_contents("npcink-abilities-toolkit.php"); if (preg_match("/^[ \t*]*Version:\s*([^\r\n]+)/mi", $s, $m)) { echo trim($m[1]); }')"
constant_version="$(php -r '$s=file_get_contents("npcink-abilities-toolkit.php"); if (preg_match("/define\(\s*'\''NPCINK_ABILITIES_TOOLKIT_VERSION'\''\s*,\s*'\''([^'\'']+)'\''\s*\)/", $s, $m)) { echo trim($m[1]); }')"
stable_tag="$(php -r '$s=file_get_contents("readme.txt"); if (preg_match("/^Stable tag:\s*([^\r\n]+)/mi", $s, $m)) { echo trim($m[1]); }')"

if [[ "$plugin_version" != "$VERSION" ]]; then
	echo "Plugin header Version ($plugin_version) does not match release candidate version ($VERSION)." >&2
	exit 1
fi

if [[ "$constant_version" != "$VERSION" ]]; then
	echo "NPCINK_ABILITIES_TOOLKIT_VERSION ($constant_version) does not match release candidate version ($VERSION)." >&2
	exit 1
fi

if [[ "$stable_tag" != "$VERSION" ]]; then
	echo "readme.txt Stable tag ($stable_tag) does not match release candidate version ($VERSION)." >&2
	exit 1
fi

tmpdir="$(mktemp -d)"
cleanup() {
	rm -rf "$tmpdir"
}
trap cleanup EXIT

package_dir="$tmpdir/$PLUGIN_SLUG"
mkdir -p "$package_dir" dist

rsync_args=("-a")
while IFS= read -r excluded_path; do
	rsync_args+=("--exclude=$excluded_path")
done < <(sed -e 's/\r$//' "$DISTIGNORE_FILE" | awk 'NF && $1 !~ /^#/')
rsync_args+=("$ROOT_DIR/" "$package_dir/")
rsync "${rsync_args[@]}"

zip_path="$ROOT_DIR/dist/$PLUGIN_SLUG-$VERSION.zip"
rm -f "$zip_path"
(
	cd "$tmpdir"
	zip -qr "$zip_path" "$PLUGIN_SLUG"
)

zip_sha256="$(php -r 'echo hash_file("sha256", $argv[1]);' "$zip_path")"
echo "Release source commit: $source_commit" >&2
echo "Release ZIP SHA-256: $zip_sha256" >&2
echo "$zip_path"
