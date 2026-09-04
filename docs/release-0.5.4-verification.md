# 0.5.4 Release Verification

Status: release candidate verification complete; publication is pending.

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
| Local WordPress smoke | Pass | Local.app smoke passed on the exact candidate source with default and light profiles (443 and 58 assertions). |
| Minimum WordPress/PHP smoke | Pass | M4 Docker smoke passed on WordPress 6.9.4 with PHP 8.0 (441 assertions). |
| Current WordPress/PHP smoke | Pass | M4 Docker smoke passed on WordPress 7.0 with PHP 8.5 (441 assertions). |
| Cross-repository quality matrix | Pending | Run the central matrix before publication. |

## Non-blocking Warnings

Plugin Check currently reports the existing translation-loading deprecation,
`meta_key` slow-query notices, and direct database no-caching notices for
bounded row locks. The `Tested up to` warning was cleared by the 7.1 readme
header.

## Publication Gate

Do not tag or publish until the cross-repository quality matrix passes. The
release payload was built from source commit
`6b81cabe119ecfd923128cdfbf1e67c8fd65e02c` and has SHA-256
`b95fc1d51303c81ebbf1ccb3fbc2ddd1094230cd6a656f5215fc96c0ca65c63e`.
Record the final tag and WordPress.org SVN revision here after publication.
