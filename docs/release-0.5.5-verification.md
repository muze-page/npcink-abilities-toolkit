# 0.5.5 Release Verification

Status: release candidate locally verified; final cross-repository gates are
pending.

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
| Cross-repository release acceptance | Pending final run | Must pass from Governance Core without skipping packaging or the signed Adapter fixture. |
| Cross-repository quality matrix | Pending final run | Must pass from the central Workflow Toolbox matrix. |

## Publication Gate

Do not create `0.5.5` or publish the package until every pending row passes and
the final package SHA-256 is recorded in the release closeout evidence.
