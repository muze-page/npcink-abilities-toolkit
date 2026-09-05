# 0.5.5 Release Verification

Status: release candidate verified with revision-bound M4 evidence;
publication operations remain pending.

This patch candidate keeps the 0.5.4 ability and workflow-definition contracts
unchanged. It isolates the packaged Plugin Check regression test from WP-CLI
runtime variables exported by a parent release process, so the test continues
to execute its intended fake `wp` command in both standalone and
cross-repository gates.

The release lane now accepts fail-closed M4 Docker evidence bound to the exact
Toolkit Git revision and distribution archive. This keeps the WordPress/PHP
compatibility matrix on the M4 host while the same `release:verify` command
still runs the source gate, PHPStan, LocalWP smoke, and packaged Plugin Check.

## Boundary

- No ability id, schema, annotation, callback, package profile, or workflow
  definition changed.
- Approval truth, audit truth, workflow runtime, cloud execution, and final
  write authorization remain outside Toolkit.
- The changes affect release test isolation and revision-bound compatibility
  evidence only; production ability, Plugin Check, and package-building
  behavior is unchanged.

## Verification Matrix

| Check | Status | Evidence |
| --- | --- | --- |
| Inherited-environment package-check regression | Pass | `test:plugin-package-check` passed with parent `WP_CLI_PHP`, error-reporting, and socket variables set. |
| `composer test:all` | Pass | All source, contract, package, lifecycle, performance, and syntax checks passed. |
| `composer analyse:phpstan` | Pass | PHPStan reported no errors on the final candidate source. |
| `composer check:boundary` | Pass | Project boundary guard passed. |
| Revision-bound evidence guard | Pass | Exact-HEAD and distribution-archive binding, both required profiles, M4 runner identity, Docker version, and freshness are validated fail closed; stale and incomplete fixtures are rejected. |
| `composer release:verify` with M4 evidence | Pass | One release command accepted exact-revision M4 evidence and passed the source gate, PHPStan, diff check, LocalWP smoke, and packaged Plugin Check. |
| Local WordPress smoke | Pass | Default and light profiles passed with 443 and 58 assertions. |
| Packaged Plugin Check | Pass with warnings | No errors; existing translation-loading and bounded database-query warnings remain. |
| M4 minimum WordPress/PHP smoke | Pass | WordPress 6.9.4 with PHP 8.0 passed 441 assertions. |
| M4 current WordPress/PHP smoke | Pass | WordPress 7.0 with PHP 8.5 passed 441 assertions. |
| Cross-repository release acceptance | Pending final rerun | The previous product gates passed, but the acceptance command must be rerun against the final exact-revision M4 evidence. |
| Cross-repository quality matrix | Pending final rerun | The previous central matrix passed; rerun it after cross-repository release acceptance on the final candidate. |

## Publication Gate

Do not create `0.5.5` or publish the package until the final Toolkit commit has
fresh exact-revision M4 evidence, the cross-repository release acceptance and
central quality matrix pass, and the final package SHA-256 is recorded. Push,
protected merge, tag creation, and WordPress.org publication remain explicit
release-owner operations.
