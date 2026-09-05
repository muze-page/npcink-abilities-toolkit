#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ -z "${WP_PATH:-}" ]]; then
	echo "WP_PATH is required for release verification." >&2
	echo "Example: WP_PATH=/path/to/wordpress composer release:verify" >&2
	exit 2
fi

if [[ ! -d "$WP_PATH" ]]; then
	echo "WP_PATH does not exist: $WP_PATH" >&2
	exit 2
fi

cd "$ROOT_DIR"

composer test:all
composer analyse:phpstan
git diff --check
if [[ -n "${NPCINK_TOOLKIT_WORDPRESS_SMOKE_EVIDENCE:-}" ]]; then
	php scripts/check-wordpress-smoke-evidence.php "$NPCINK_TOOLKIT_WORDPRESS_SMOKE_EVIDENCE"
else
	composer smoke:wp-minimum
fi
composer smoke:wp
composer check:plugin-package
