# 0.5.4 Release Verification

Status: release candidate verification in progress.

This note records the 0.5.4 candidate built from the clean `origin/master`
baseline plus the reviewed internal-link and media lifecycle changes. The
release keeps approval truth, audit truth, workflow runtime, cloud execution,
and final write authorization outside Toolkit.

## Changes Verified

- Media backup cleanup is bounded to 500 attachments per run.
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
| `composer test:all` | Pass | 165 ability contracts, 7 workflow recipes, 6732 assertions, boundary checks, performance checks, and PHP syntax lint passed. |
| `composer analyse:phpstan` | Pass | No errors on the clean release candidate. |
| `composer check:boundary` | Pass | Project boundary guard passed. |
| `composer check:wporg` | Pass | WordPress.org review guard passed. |
| `composer check:plugin-package` | Pass with warnings | Packaged Plugin Check reported no errors; warnings are recorded below. |
| Local WordPress smoke | Pending on candidate install | Existing Local.app smoke remains available; rerun against the exact 0.5.4 package before publication. |
| Minimum WordPress/PHP smoke | Blocked | Docker daemon is unavailable on this device. |
| Cross-repository quality matrix | Pending | Run the central matrix before publication. |

## Non-blocking Warnings

Plugin Check currently reports the existing translation-loading deprecation,
`meta_key` slow-query notices, direct database no-caching notices for bounded
row locks, and the `Tested up to` value. The readme now declares WordPress 7.1;
rerun Plugin Check after packaging to confirm the header warning is cleared.

## Publication Gate

Do not tag or publish until the exact candidate commit has passed the minimum
runtime smoke, current Local smoke, and cross-repository quality matrix. Record
the final commit, ZIP SHA-256, and WordPress.org SVN revision here before
publication.
