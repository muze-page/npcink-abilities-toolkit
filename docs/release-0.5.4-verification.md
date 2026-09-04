# 0.5.4 Release Verification

Status: release candidate verification rerun complete; final commit and
cross-repository validation are pending.

This note records the 0.5.4 candidate built from the clean `origin/master`
baseline plus the reviewed internal-link and media lifecycle changes. The
release keeps approval truth, audit truth, workflow runtime, cloud execution,
and final write authorization outside Toolkit.

## Changes Verified

- Media backup cleanup is bounded to 500 attachments per run and advances with
  a stable attachment-ID cursor rather than accumulating or rescanning the
  complete history population.
- Exact-manifest records marked `manual_confirmation_required` are not removed
  by the daily maintenance action.
- Cleanup scheduling follows the `core_write` package profile and uninstall
  clears the cleanup cron.
- Internal-link candidates require semantic body evidence and hand off final
  application to the visible editor.
- Media operations retain canonical SHA-256 fingerprints and replacement
  lineage evidence.

## Verification Matrix

| Check | Status | Evidence |
| --- | --- | --- |
| `composer test:all` | Pass | 165 ability contracts, 7 workflow recipes, 6743 assertions, boundary checks, performance checks, profile/Cron behavior checks, packaged Plugin Check behavior checks, and PHP syntax lint passed. |
| `composer analyse:phpstan` | Pass | No errors on the current candidate source. |
| `composer check:boundary` | Pass | Project boundary guard passed. |
| `composer check:wporg` | Pass | WordPress.org review guard passed. |
| `composer check:plugin-package` | Pass with warnings | Packaged Plugin Check reported no errors; warnings are recorded below. |
| Local WordPress smoke | Pass | Local.app smoke passed on the exact candidate source with default and light profiles (443 and 58 assertions). |
| Minimum WordPress/PHP smoke | Pass | M4 Docker smoke passed on WordPress 6.9.4 with PHP 8.0 (441 assertions). |
| Current WordPress/PHP smoke | Pass | M4 Docker smoke passed on WordPress 7.0 with PHP 8.5 (441 assertions). |
| Cross-repository quality matrix | Needs validation | Central matrix ran local gates successfully for Toolkit, Core, Adapter, Toolbox, and Cloud Addon; the overall matrix stopped because `npcink-ai-cloud` has a dirty worktree and requires exact-SHA GitHub CI validation. |

## Non-blocking Warnings

Plugin Check currently reports the existing translation-loading deprecation,
`meta_key` slow-query notices, and direct database no-caching notices for
bounded row locks. The `Tested up to` warning was cleared by the 7.1 readme
header.

## Publication Gate

Do not tag or publish until these verification fixes are committed, the release
payload is rebuilt from that exact commit, its SHA-256 is recorded, and the
cross-repository quality matrix passes. Record the final source commit, payload
hash, tag, and WordPress.org SVN revision here after publication.
