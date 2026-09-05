#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GUARD="$ROOT_DIR/scripts/check-release-source.sh"
BUILDER="$ROOT_DIR/scripts/build-release-zip.sh"
TMPDIR_ROOT="$(mktemp -d)"
TEST_REPO="$TMPDIR_ROOT/release-source"
PACKAGE_REPO="$TMPDIR_ROOT/release-package"

cleanup() {
	rm -rf "$TMPDIR_ROOT"
}
trap cleanup EXIT

mkdir -p "$TEST_REPO"
git -C "$TEST_REPO" init -q
git -C "$TEST_REPO" config user.name "Npcink Release Guard Test"
git -C "$TEST_REPO" config user.email "release-guard@example.invalid"

printf 'tagged source\n' > "$TEST_REPO/source.txt"
git -C "$TEST_REPO" add source.txt
git -C "$TEST_REPO" commit -q -m "tagged source"
git -C "$TEST_REPO" tag 0.5.3

tagged_commit="$(git -C "$TEST_REPO" rev-parse HEAD)"
guarded_commit="$(bash "$GUARD" 0.5.3 "$TEST_REPO")"
if [[ "$guarded_commit" != "$tagged_commit" ]]; then
	echo "Release source guard did not return the tagged source commit." >&2
	exit 1
fi

printf 'new source\n' > "$TEST_REPO/source.txt"
git -C "$TEST_REPO" add source.txt
git -C "$TEST_REPO" commit -q -m "new source"

if bash "$GUARD" 0.5.3 "$TEST_REPO" >/dev/null 2>&1; then
	echo "Release source guard allowed a historical version from a different commit." >&2
	exit 1
fi

if ! bash "$GUARD" 0.5.5 "$TEST_REPO" >/dev/null; then
	echo "Release source guard rejected a clean source for a new version." >&2
	exit 1
fi

printf 'dirty source\n' >> "$TEST_REPO/source.txt"
if bash "$GUARD" 0.5.5 "$TEST_REPO" >/dev/null 2>&1; then
	echo "Release source guard allowed a dirty worktree." >&2
	exit 1
fi

if bash "$GUARD" invalid-version "$TEST_REPO" >/dev/null 2>&1; then
	echo "Release source guard allowed an invalid version." >&2
	exit 1
fi

mkdir -p "$PACKAGE_REPO/scripts" "$PACKAGE_REPO/docs"
git -C "$PACKAGE_REPO" init -q
git -C "$PACKAGE_REPO" config user.name "Npcink Release Guard Test"
git -C "$PACKAGE_REPO" config user.email "release-guard@example.invalid"
cp "$GUARD" "$PACKAGE_REPO/scripts/check-release-source.sh"
cp "$BUILDER" "$PACKAGE_REPO/scripts/build-release-zip.sh"
printf 'dist\ndocs\n' > "$PACKAGE_REPO/.gitignore"
printf '.git\ndist\ndocs\n' > "$PACKAGE_REPO/.distignore"
printf '<?php\n/*\nPlugin Name: Reproducible Package\nVersion: 0.5.5\n*/\ndefine( '\''NPCINK_ABILITIES_TOOLKIT_VERSION'\'', '\''0.5.5'\'' );\n' > "$PACKAGE_REPO/npcink-abilities-toolkit.php"
printf 'Stable tag: 0.5.5\n' > "$PACKAGE_REPO/readme.txt"
printf 'excluded release note\n' > "$PACKAGE_REPO/docs/release-note.md"
git -C "$PACKAGE_REPO" add .
git -C "$PACKAGE_REPO" commit -q -m "package fixture"

VERSION=0.5.5 bash "$PACKAGE_REPO/scripts/build-release-zip.sh" >/dev/null 2>&1
first_package_sha="$(shasum -a 256 "$PACKAGE_REPO/dist/npcink-abilities-toolkit-0.5.5.zip" | awk '{print $1}')"
touch "$PACKAGE_REPO"
VERSION=0.5.5 bash "$PACKAGE_REPO/scripts/build-release-zip.sh" >/dev/null 2>&1
second_package_sha="$(shasum -a 256 "$PACKAGE_REPO/dist/npcink-abilities-toolkit-0.5.5.zip" | awk '{print $1}')"
if [[ "$first_package_sha" != "$second_package_sha" ]]; then
	echo "Release ZIP checksum changed when only excluded directory metadata changed." >&2
	exit 1
fi

echo "OK: release source guard"
