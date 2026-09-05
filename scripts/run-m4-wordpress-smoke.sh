#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
M4_HOST="${NPCINK_TOOLKIT_M4_HOST:-muze@172.16.3.35}"
REMOTE_WORKSPACE_ROOT="${NPCINK_TOOLKIT_M4_WORKSPACE_ROOT:-/Users/muze/docker-workspaces}"
OUTPUT_PATH="${NPCINK_TOOLKIT_WORDPRESS_SMOKE_OUTPUT:-$ROOT_DIR/build/m4-wordpress-smoke-evidence.json}"

for command_name in git jq scp ssh shasum tar; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Missing required command: $command_name" >&2
		exit 127
	fi
done

if [[ -n "$(git -C "$ROOT_DIR" status --porcelain)" ]]; then
	echo "M4 compatibility smoke requires a clean Toolkit worktree." >&2
	exit 1
fi

source_revision="$(git -C "$ROOT_DIR" rev-parse HEAD)"
short_revision="${source_revision:0:12}"
local_tmp="$(mktemp -d "${TMPDIR:-/tmp}/npcink-toolkit-m4.XXXXXX")"
archive_path="$local_tmp/source.tar"
remote_dir=''

cleanup() {
	if [[ -n "$remote_dir" ]]; then
		ssh "$M4_HOST" "case '$remote_dir' in '$REMOTE_WORKSPACE_ROOT'/npcink-toolkit-release.*) rm -rf -- '$remote_dir' ;; *) exit 2 ;; esac" >/dev/null 2>&1 || true
	fi
	rm -rf "$local_tmp"
}
trap cleanup EXIT

git -C "$ROOT_DIR" archive --format=tar HEAD > "$archive_path"
archive_sha256="$(shasum -a 256 "$archive_path" | awk '{print $1}')"
remote_dir="$(ssh "$M4_HOST" "mkdir -p '$REMOTE_WORKSPACE_ROOT' && mktemp -d '$REMOTE_WORKSPACE_ROOT/npcink-toolkit-release.XXXXXX'")"
if [[ "$remote_dir" != "$REMOTE_WORKSPACE_ROOT"/npcink-toolkit-release.* ]]; then
	echo "M4 returned an unexpected workspace path: $remote_dir" >&2
	exit 1
fi

scp -q "$archive_path" "$M4_HOST:$remote_dir/source.tar"
ssh "$M4_HOST" "mkdir -p '$remote_dir/repo' && tar -xf '$remote_dir/source.tar' -C '$remote_dir/repo'"
docker_version="$(ssh "$M4_HOST" 'docker version --format "{{.Server.Version}}"')"

minimum_output="$(ssh "$M4_HOST" "cd '$remote_dir/repo' && MINIMUM_WP_PROJECT_NAME='npcink_toolkit_69_${short_revision}' MINIMUM_WP_HTTP_PORT=8911 bash scripts/minimum-wordpress-smoke.sh" 2>&1)"
printf '%s\n' "$minimum_output"
minimum_assertions="$(printf '%s\n' "$minimum_output" | sed -nE 's/^OK: ([0-9]+) assertions$/\1/p' | tail -n 1)"

current_output="$(ssh "$M4_HOST" "cd '$remote_dir/repo' && MINIMUM_WP_VERSION=7.0 MINIMUM_WP_PHP_VERSION=8.5 MINIMUM_WP_PROJECT_NAME='npcink_toolkit_70_${short_revision}' MINIMUM_WP_HTTP_PORT=8912 WORDPRESS_SMOKE_LABEL=Current bash scripts/minimum-wordpress-smoke.sh" 2>&1)"
printf '%s\n' "$current_output"
current_assertions="$(printf '%s\n' "$current_output" | sed -nE 's/^OK: ([0-9]+) assertions$/\1/p' | tail -n 1)"

if [[ ! "$minimum_assertions" =~ ^[1-9][0-9]*$ || ! "$current_assertions" =~ ^[1-9][0-9]*$ ]]; then
	echo "M4 smoke output did not contain both assertion totals." >&2
	exit 1
fi

mkdir -p "$(dirname "$OUTPUT_PATH")"
jq -n \
	--arg source_revision "$source_revision" \
	--arg source_archive_sha256 "$archive_sha256" \
	--arg docker_server_version "$docker_version" \
	--arg generated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
	--argjson minimum_assertions "$minimum_assertions" \
	--argjson current_assertions "$current_assertions" \
	'{
		schema_version: "npcink_toolkit_wordpress_smoke_evidence.v1",
		runner: "m4-docker",
		source_revision: $source_revision,
		source_archive_sha256: $source_archive_sha256,
		docker_server_version: $docker_server_version,
		generated_at: $generated_at,
		profiles: {
			"wordpress-6.9.4-php-8.0": {wordpress: "6.9.4", php: "8.0", assertions: $minimum_assertions, status: "passed"},
			"wordpress-7.0-php-8.5": {wordpress: "7.0", php: "8.5", assertions: $current_assertions, status: "passed"}
		}
	}' > "$OUTPUT_PATH"

php "$ROOT_DIR/scripts/check-wordpress-smoke-evidence.php" "$OUTPUT_PATH"
echo "M4 WordPress smoke evidence ready: $OUTPUT_PATH"
