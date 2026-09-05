#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GUARD="$ROOT_DIR/scripts/check-release-source.sh"
TMPDIR_ROOT="$(mktemp -d)"
TEST_REPO="$TMPDIR_ROOT/release-source"

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

echo "OK: release source guard"
