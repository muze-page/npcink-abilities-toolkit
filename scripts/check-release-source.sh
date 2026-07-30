#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-}"
ROOT_DIR="${2:-}"

if [[ -z "$VERSION" || -z "$ROOT_DIR" ]]; then
	echo "Usage: check-release-source.sh VERSION ROOT_DIR" >&2
	exit 2
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z][0-9A-Za-z.-]*)?$ ]]; then
	echo "Release version is not a supported semantic version: $VERSION" >&2
	exit 2
fi

if ! git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "Release source must be a Git worktree: $ROOT_DIR" >&2
	exit 1
fi

if [[ "$(git -C "$ROOT_DIR" rev-parse --is-shallow-repository)" == "true" ]]; then
	echo "Release source is shallow; fetch full history and tags before packaging." >&2
	exit 1
fi

dirty_paths="$(
	git -C "$ROOT_DIR" status --porcelain --untracked-files=all -- . ':(exclude)dist'
)"
if [[ -n "$dirty_paths" ]]; then
	echo "Release source must be clean outside dist/." >&2
	printf '%s\n' "$dirty_paths" >&2
	exit 1
fi

head_commit="$(git -C "$ROOT_DIR" rev-parse HEAD)"
tag_ref="refs/tags/$VERSION"
if tagged_commit="$(git -C "$ROOT_DIR" rev-parse --verify --quiet "${tag_ref}^{commit}")"; then
	if [[ "$head_commit" != "$tagged_commit" ]]; then
		echo "Release $VERSION already exists at $tagged_commit; current source is $head_commit." >&2
		echo "Historical versions may only be rebuilt from their exact tagged commit." >&2
		exit 1
	fi
fi

printf '%s\n' "$head_commit"
