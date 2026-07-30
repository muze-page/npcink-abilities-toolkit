#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="${PLUGIN_SLUG:-npcink-abilities-toolkit}"
VERSION="${1:-${VERSION:-}}"
SVN_WC="${WPORG_SVN_WC:-$ROOT_DIR/build/wporg-svn-wc/$PLUGIN_SLUG}"
ASSET_DIR="${WPORG_ASSET_DIR:-$ROOT_DIR/sj/exports/wordpress-org}"
DISTIGNORE_FILE="$ROOT_DIR/.distignore"

cd "$ROOT_DIR"

if [[ -z "$VERSION" ]]; then
	echo "Release version is required." >&2
	echo "Example: VERSION=0.6.0 composer release:prepare-wporg" >&2
	exit 2
fi

if [[ ! -d "$SVN_WC/.svn" ]]; then
	echo "WordPress.org SVN working copy not found: $SVN_WC" >&2
	exit 1
fi

source_commit="$(bash "$ROOT_DIR/scripts/check-release-source.sh" "$VERSION" "$ROOT_DIR")"

plugin_version="$(php -r '$s=file_get_contents("npcink-abilities-toolkit.php"); if (preg_match("/^[ \t*]*Version:\s*([^\r\n]+)/mi", $s, $m)) { echo trim($m[1]); }')"
constant_version="$(php -r '$s=file_get_contents("npcink-abilities-toolkit.php"); if (preg_match("/define\(\s*'\''NPCINK_ABILITIES_TOOLKIT_VERSION'\''\s*,\s*'\''([^'\'']+)'\''\s*\)/", $s, $m)) { echo trim($m[1]); }')"
stable_tag="$(php -r '$s=file_get_contents("readme.txt"); if (preg_match("/^Stable tag:\s*([^\r\n]+)/mi", $s, $m)) { echo trim($m[1]); }')"

if [[ "$plugin_version" != "$VERSION" ]]; then
	echo "Plugin header Version ($plugin_version) does not match release version ($VERSION)." >&2
	exit 1
fi

if [[ "$constant_version" != "$VERSION" ]]; then
	echo "NPCINK_ABILITIES_TOOLKIT_VERSION ($constant_version) does not match release version ($VERSION)." >&2
	exit 1
fi

if [[ "$stable_tag" != "$VERSION" ]]; then
	echo "readme.txt Stable tag ($stable_tag) does not match release version ($VERSION)." >&2
	exit 1
fi

tag_dir="$SVN_WC/tags/$VERSION"
if [[ -e "$tag_dir" && "${ALLOW_REPLACE_WPORG_TAG:-}" != "1" ]]; then
	echo "SVN tag already exists: $tag_dir" >&2
	echo "Set ALLOW_REPLACE_WPORG_TAG=1 only for an intentional local restage before SVN commit." >&2
	exit 1
fi

tmpdir="$(mktemp -d)"
cleanup() {
	rm -rf "$tmpdir"
}
trap cleanup EXIT

package_dir="$tmpdir/$PLUGIN_SLUG"
mkdir -p "$package_dir"

rsync_args=("-a")
while IFS= read -r excluded_path; do
	rsync_args+=("--exclude=$excluded_path")
done < <(sed -e 's/\r$//' "$DISTIGNORE_FILE" | awk 'NF && $1 !~ /^#/')
rsync_args+=("$ROOT_DIR/" "$package_dir/")
rsync "${rsync_args[@]}"

mkdir -p "$SVN_WC/trunk" "$SVN_WC/tags" "$SVN_WC/assets"
rsync -a --delete "$package_dir/" "$SVN_WC/trunk/"

rm -rf "$tag_dir"
mkdir -p "$tag_dir"
rsync -a "$package_dir/" "$tag_dir/"

if [[ -d "$ASSET_DIR" ]]; then
	rsync -a --delete "$ASSET_DIR/" "$SVN_WC/assets/"
fi

svn add --force "$SVN_WC/trunk" "$SVN_WC/tags/$VERSION" "$SVN_WC/assets" >/dev/null
svn status "$SVN_WC" | awk '$1 == "!" { print substr($0, 9) }' | while IFS= read -r missing_path; do
	[[ -n "$missing_path" ]] && svn delete "$missing_path"
done

svn status "$SVN_WC"
echo
echo "Prepared WordPress.org SVN working copy for $PLUGIN_SLUG $VERSION."
echo "Release source commit: $source_commit"
echo "Review the SVN status above, then commit from your terminal:"
echo "SVN_USERNAME=muze233 COMMIT_MESSAGE=\"Release $VERSION\" build/commit-wporg-release.sh"
