# WordPress.org Release Runbook

Status: active release operations note.
Last updated: 2026-06-06.

This document records the `0.5.0` WordPress.org SVN publishing flow and the
local Homebrew/Subversion recovery steps used on macOS. It is written for
future maintainers and AI agents so the next release does not need to rediscover
the same environment issues.

## Release Shape

The WordPress.org plugin slug is:

```sh
npcink-abilities-toolkit
```

The WordPress.org SVN repository is:

```sh
https://plugins.svn.wordpress.org/npcink-abilities-toolkit/
```

The local SVN working copy used for publishing is:

```sh
/Users/muze/gitee/npcink-abilities-toolkit/build/wporg-svn-wc/npcink-abilities-toolkit
```

The commit helper is:

```sh
/Users/muze/gitee/npcink-abilities-toolkit/build/commit-wporg-release.sh
```

`build/` is ignored by Git. Treat it as local release state, not source code.
The source-controlled helper for preparing that local SVN working copy is:

```sh
VERSION=X.Y.Z composer release:prepare-wporg
```

## Pre-Commit Verification

Before committing to WordPress.org SVN, run the project release checks from the
Git workspace:

```sh
cd /Users/muze/gitee/npcink-abilities-toolkit
git fetch --prune --tags origin
composer check:boundary
composer check:wporg
WP_PATH=/path/to/wordpress composer release:verify
```

Release packaging and SVN preparation require a clean, non-shallow Git
worktree. If the requested version already has a Git tag, `HEAD` must match that
tag's commit exactly. The release ZIP command prints the source commit and
SHA-256 checksum so the reviewed artifact can be tied back to immutable source.

For Local.app sites that expose MySQL only through a socket, pass the socket to
WP-CLI through the package check helper:

```sh
WP_PATH="/Users/muze/Local Sites/magick-ai/app/public" \
WP_CLI_MYSQL_SOCKET="/Users/muze/Library/Application Support/Local/run/NPb24Zg9g/mysql/mysqld.sock" \
composer release:verify
```

When the local Docker daemon is unavailable, generate exact-revision M4
compatibility evidence first and pass it to the same release gate:

```sh
composer smoke:wp-m4
NPCINK_TOOLKIT_WORDPRESS_SMOKE_EVIDENCE="$PWD/build/m4-wordpress-smoke-evidence.json" \
WP_PATH="/Users/muze/Local Sites/magick-ai/app/public" \
WP_CLI_MYSQL_SOCKET="/Users/muze/Library/Application Support/Local/run/NPb24Zg9g/mysql/mysqld.sock" \
composer release:verify
```

The evidence must name the current Git HEAD and contain passing minimum and
current WordPress/PHP profiles. It replaces only the duplicate local Docker
leg; the source, PHPStan, LocalWP, and packaged Plugin Check gates still run.

For the `0.5.0` release line, these checks passed before SVN publishing:

- `composer test:all`
- `composer analyse:phpstan`
- `git diff --check`
- `composer smoke:wp`
- `composer check:plugin-package`

If the Git working tree has unrelated local edits, do not sync the SVN release
from the dirty worktree. Build or stage from the release tag/commit instead.
For `0.5.0`, the SVN working copy was prepared from Git tag `0.5.0` at commit
`ff3e88b`, while unrelated local edits existed in the Git workspace.

## Locale Release Safety

Bundled runtime locale files under `languages/` are repository-maintained
starter translations. They are separate from WordPress.org directory
translations, which are managed through translate.wordpress.org/GlotPress and
may require locale editor approval before becoming visible on localized plugin
directory pages.

Only ship bundled locale packs that are intentionally maintained as complete
starter sets. If a locale is incomplete, remove both its `.po` and `.mo` files
from the source package until it can be maintained properly, and record the
reason in `readme.txt`, `CHANGELOG.md`, or the release verification note.

`composer check:wporg` verifies the known bundled starter locale set, rejects
known-incomplete locale packs such as `zh_TW`, and fails when a `.po` file does
not have the matching `.mo` file or vice versa. Keep that guard aligned with
the files that are actually intended to ship in `languages/`.

## Commit Command

Run the commit from the user's own terminal so the WordPress.org SVN password is
typed into the terminal prompt, not into chat:

```sh
cd /Users/muze/gitee/npcink-abilities-toolkit
SVN_USERNAME=muze233 COMMIT_MESSAGE="Release 0.5.0" build/commit-wporg-release.sh
```

The helper prints the SVN status before committing. Confirm the status matches
the intended release changes, including the new tag directory and trunk updates.

For future releases, replace the commit message version:

```sh
VERSION=X.Y.Z composer release:prepare-wporg
SVN_USERNAME=muze233 COMMIT_MESSAGE="Release X.Y.Z" build/commit-wporg-release.sh
```

## Expected SVN State

Before a release commit, these commands should work:

```sh
svn info /Users/muze/gitee/npcink-abilities-toolkit/build/wporg-svn-wc/npcink-abilities-toolkit
svn status /Users/muze/gitee/npcink-abilities-toolkit/build/wporg-svn-wc/npcink-abilities-toolkit
```

For `0.5.0`, the working copy pointed at:

```sh
https://plugins.svn.wordpress.org/npcink-abilities-toolkit
```

The remote revision observed before commit was `3562993`. The previous last
changed revision was `3561889` by `muze233`.

## Subversion Availability on macOS

The release helper prefers local `svn`. If `svn` exists, it does not use Docker:

```sh
command -v svn
svn --version --quiet
```

After recovery on 2026-06-06, local Subversion was:

```sh
/opt/homebrew/bin/svn
1.14.5
```

If `svn` is missing, install it with Homebrew:

```sh
brew install subversion
```

## WP-CLI Availability On This Device

WP-CLI is installed globally through Homebrew on this macOS device:

```sh
command -v wp
wp --info
```

The current path is:

```sh
/opt/homebrew/bin/wp
```

This is a device-level CLI tool, so other local WordPress projects can use the
same `wp` command when they pass their own `--path` or `WP_PATH`. WP-CLI does
not replace a WordPress installation; it operates against an existing site. Keep
the Local.app WordPress environment for release smoke checks, Plugin Check, and
site-specific verification.

## Homebrew Portable Ruby Failure

On 2026-06-06, `brew install subversion` initially failed because Homebrew's
portable Ruby was broken:

```text
zsh: killed brew install subversion
```

The root cause was not Subversion. Homebrew's portable Ruby symlink pointed at
`4.0.5_1`, and that Ruby process exited with status `137` / `SIGKILL`.

Observed bad state:

```sh
/opt/homebrew/Library/Homebrew/vendor/portable-ruby/current -> 4.0.5_1
/opt/homebrew/Library/Homebrew/vendor/portable-ruby/4.0.5_1/bin/ruby -v
# exited 137
```

Older portable Ruby versions were present and usable:

```sh
/opt/homebrew/Library/Homebrew/vendor/portable-ruby/4.0.3/bin/ruby -v
```

Homebrew then tried to fetch `portable-ruby-4.0.5_1`, but the configured bottle
mirror did not have the package and the GitHub Container Registry download was
very slow. A previous corrupt cache also caused:

```text
Error: Checksum mismatch.
Archive: /Users/muze/Library/Caches/Homebrew/portable-ruby-4.0.5_1.arm64_big_sur.bottle.tar.gz
```

When this happens, remove corrupt or incomplete cache files before retrying:

```sh
rm -f ~/Library/Caches/Homebrew/portable-ruby-4.0.5_1.arm64_big_sur.bottle.tar.gz
rm -f ~/Library/Caches/Homebrew/portable-ruby-4.0.5_1.arm64_big_sur.bottle.tar.gz.incomplete
rm -f /tmp/portable-ruby-ghcr.tar.gz
```

## Temporary Ruby Workaround Used

The clean fix is to switch to a working network and let Homebrew download the
expected portable Ruby. If that is too slow and a known-good portable Ruby is
already installed, a temporary local workaround can unblock `svn` installation.

The workaround used on 2026-06-06:

```sh
cd /opt/homebrew/Library/Homebrew/vendor/portable-ruby
rm current
ln -s 4.0.3 current

cp /opt/homebrew/Library/Homebrew/vendor/portable-ruby-version \
  /opt/homebrew/Library/Homebrew/vendor/portable-ruby-version.bak-20260606170250

printf '4.0.3\n' > /opt/homebrew/Library/Homebrew/vendor/portable-ruby-version
brew config
HOMEBREW_NO_AUTO_UPDATE=1 brew install subversion
```

After this, `brew config` reported:

```text
Homebrew Ruby: 4.0.3 => /opt/homebrew/Library/Homebrew/vendor/portable-ruby/4.0.3/bin/ruby
```

The backup file was:

```sh
/opt/homebrew/Library/Homebrew/vendor/portable-ruby-version.bak-20260606170250
```

This workaround makes Homebrew report a dirty installation, for example:

```text
HOMEBREW_VERSION: 5.1.15-dirty
```

That dirty state is expected while the local version file differs from the
Homebrew-managed value. It should be reverted after Homebrew can successfully
install the official portable Ruby again.

## Reverting the Ruby Workaround

When network access to Homebrew/GHCR is reliable again, restore the version file
and let Homebrew repair its portable Ruby:

```sh
cp /opt/homebrew/Library/Homebrew/vendor/portable-ruby-version.bak-20260606170250 \
  /opt/homebrew/Library/Homebrew/vendor/portable-ruby-version

rm -f ~/Library/Caches/Homebrew/portable-ruby-4.0.5_1.arm64_big_sur.bottle.tar.gz
rm -f ~/Library/Caches/Homebrew/portable-ruby-4.0.5_1.arm64_big_sur.bottle.tar.gz.incomplete

brew update
brew config
```

Only do this when there is time to let Homebrew download the expected portable
Ruby. Do not revert immediately before a time-sensitive SVN release unless
`svn` has already been installed and verified.

## Docker Fallback

The release helper falls back to Docker only when local `svn` is unavailable.
The Docker fallback previously failed because Debian `apt` could not fetch a
package cleanly:

```text
E: Failed to fetch http://deb.debian.org/.../openssl_..._arm64.deb
500 reading HTTP response body: unexpected EOF
sh: 1: svn: not found
```

This is a container package download failure, not a WordPress.org authentication
or SVN password problem.

If Docker is needed again and Debian apt is flaky, an Alpine one-off command can
be used:

```sh
cd /Users/muze/gitee/npcink-abilities-toolkit

docker run --rm -it \
  -v "$PWD/build/wporg-svn-wc/npcink-abilities-toolkit:/work" \
  -e SVN_USERNAME="muze233" \
  -e COMMIT_MESSAGE="Release X.Y.Z" \
  alpine:3.20 \
  sh -lc '
    set -e
    apk add --no-cache subversion ca-certificates
    cd /work
    svn status
    svn commit --username "$SVN_USERNAME" --force-interactive -m "$COMMIT_MESSAGE"
  '
```

Prefer local `svn` on macOS once installed, because it gives the user a normal
interactive password prompt and avoids transient container package mirrors.

## Do Not Do

- Do not paste the WordPress.org SVN password into chat.
- Do not assume a Docker apt failure means WordPress.org rejected the commit.
- Do not prepare a release from a dirty Git worktree when a release tag exists.
- Do not include unrelated local Git edits in the WordPress.org SVN working
  copy.
- Do not treat `build/` as source-controlled release truth; it is local,
  ignored state.
- Do not leave Homebrew's temporary Ruby workaround undocumented, because it
  explains the `HOMEBREW_VERSION: ...-dirty` state.
