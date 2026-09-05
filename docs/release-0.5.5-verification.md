# 0.5.5 Release Verification

Status: release candidate verified; publication operations remain pending.

This patch candidate keeps the 0.5.4 ability and workflow-definition contracts
unchanged. It isolates the packaged Plugin Check regression test from WP-CLI
runtime variables exported by a parent release process, so the test continues
to execute its intended fake `wp` command in both standalone and
cross-repository gates.

## Boundary

- No ability id, schema, annotation, callback, package profile, or workflow
  definition changed.
- Approval truth, audit truth, workflow runtime, cloud execution, and final
  write authorization remain outside Toolkit.
- The change affects test process isolation only; production Plugin Check and
  package-building behavior is unchanged.

## Verification Matrix

| Check | Status | Evidence |
| --- | --- | --- |
| Inherited-environment package-check regression | Pass | `test:plugin-package-check` passed with parent `WP_CLI_PHP`, error-reporting, and socket variables set. |
| `composer test:all` | Pass | All source, contract, package, lifecycle, performance, and syntax checks passed. |
| `composer analyse:phpstan` | Pass | PHPStan reported no errors on the final candidate source. |
| `composer check:boundary` | Pass | Project boundary guard passed. |
| Release verification components | Pass | The source gate, PHPStan, diff check, LocalWP smoke, packaged Plugin Check, and both Docker compatibility smokes passed. The monolithic local command was split only because the local Docker daemon disconnected during the minimum-version step. |
| Local WordPress smoke | Pass | Default and light profiles passed with 443 and 58 assertions. |
| Packaged Plugin Check | Pass with warnings | No errors; existing translation-loading and bounded database-query warnings remain. |
| M4 minimum WordPress/PHP smoke | Pass | WordPress 6.9.4 with PHP 8.0 passed 441 assertions. |
| M4 current WordPress/PHP smoke | Pass | WordPress 7.0 with PHP 8.5 passed 441 assertions. |
| Cross-repository release acceptance | Product gates pass; command incomplete | Core, Adapter packaging, package-install smoke, and the signed commit-enabled Adapter fixture passed. Toolkit source, PHPStan, and package-check isolation also passed. The command then exited only because Toolkit's duplicate minimum-version leg attempted to use the unavailable local Docker daemon; the same candidate passed that leg on M4. |
| Cross-repository quality matrix | Pass | The central six-repository matrix passed all configured gates on 2026-09-05. It reported unrelated uncommitted changes in Workflow Toolbox. |

## Publication Gate

Do not create `0.5.5` or publish the package until the release owner either
restores local Docker and obtains one green monolithic cross-repository run, or
adopts a revision-bound split-executor gate that treats the recorded M4 Docker
results as the minimum/current compatibility lane. The release closeout must
also record the final package SHA-256.
