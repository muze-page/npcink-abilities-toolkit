# Testing Strategy

Status: active
Date: 2026-06-07

This package is a foundation layer for WordPress Abilities API contracts. Its
tests should protect stable ability discovery, schema shape, risk metadata,
host-governed write boundaries, and consumer handoff behavior. They should not
grow into broad product workflow automation or coverage-driven test volume.

## Default Gate

Run this before merging source changes that affect registered abilities,
schemas, metadata, workflow recipes, projection, diagnostics, or host-governed
write behavior:

```bash
composer test:all
```

`composer test:all` is the default local source gate. It runs composer
validation, Composer dependency advisory audit, project boundary checks,
contract readiness, consumer handoff, workflow consumer proof, official stack
compatibility, MCP exposure, provider demo compatibility, catalog audit,
WordPress.org static review checks, bootstrap and runtime performance smoke,
lightweight regression tests, and PHP syntax linting.

Run targeted gates while iterating:

```bash
composer test
composer audit:composer
composer check:boundary
composer check:contracts
composer check:consumer
composer check:workflow-consumer
composer check:official-stack
composer check:mcp-exposure
composer check:provider-demo
composer check:catalog
composer check:wporg
composer perf:bootstrap
composer perf:smoke
composer pilot:gutenberg-composer
```

Use `composer smoke:wp` only when a real WordPress site is available. Use
`composer e2e:official-stack` when changing assumptions about the official
Abilities API, AI plugin, or MCP Adapter stack.

## Release And Packaging Gates

`composer test:all` is not the complete WordPress.org publishing gate. Before
tagging or committing a package to WordPress.org SVN, follow
[WordPress.org Release Runbook](wordpress-org-release-runbook.md) and run the
additional release checks there:

```bash
composer analyse:phpstan
composer smoke:wp-minimum
composer smoke:wp-m4
composer smoke:wp
composer check:plugin-package
```

`composer smoke:wp-minimum` runs the real WordPress smoke against WordPress
6.9.4 in a disposable Docker environment. `composer smoke:wp` should target the
current tested-up-to WordPress line. Both are release gates when the declared
minimum or official-stack assumptions change.

When Docker runs on the M4 test host instead of the source machine, run
`composer smoke:wp-m4` from a clean Toolkit checkout. It archives the exact
current Git revision, runs WordPress 6.9.4/PHP 8.0 and WordPress 7.0/PHP 8.5 on
M4, cleans the remote workspace, and writes ignored revision-bound evidence to
`build/m4-wordpress-smoke-evidence.json`. Pass that path through
`NPCINK_TOOLKIT_WORDPRESS_SMOKE_EVIDENCE` to `composer release:verify`; stale,
incomplete, or non-M4 evidence fails closed.

Use `composer analyse:phpstan` when public PHP contracts, class boundaries, or
bootstrap assumptions change, even outside a WordPress.org release. Use
`composer check:plugin-package` only in a WordPress environment with WP-CLI and
Plugin Check available.

## What To Test

Prefer focused contract and invariant tests. Add or update coverage when a
change can affect:

- public helper behavior;
- ability id, category, label, description, and contract version stability;
- input and output schema normalization;
- read/write/destructive risk metadata;
- `requires_confirm`, `requires_approval`, `dry_run`, `commit`, and
  `idempotency_key` behavior;
- fail-safe conflicting write-control behavior, explicit safe post-meta reads,
  host-approved setting targets, and remote-media validation before uploads;
- REST, MCP, and Npcink metadata consistency;
- MCP public exposure allowlists and server routing;
- workflow recipe discovery, replay fixture parity, and read-only entrypoints;
- consumer proposal payload handoff;
- provider demo compatibility with public helpers;
- diagnostic redaction and bounded performance behavior.

## Change-To-Gate Rules

Use these targeted rules while developing. The full `composer test:all` source
gate still runs before merge, but these commands identify which contract should
move with each kind of change.

| Change type | Required coverage or gate |
| --- | --- |
| `includes/Registry/*`, public helper registration, schema normalization, or annotation normalization | Add or update focused assertions in `tests/run.php`, then run `composer test` and `composer check:contracts`. |
| First-party ability ids, labels, descriptions, categories, schemas, risk metadata, approval flags, dry-run controls, or idempotency controls | Add or update `composer check:contracts` coverage, and update catalog or consumer fixtures when the public contract intentionally changes. |
| Consumer handoff shape, Core governance proposal payloads, or high-value write/destructive target discovery | Run and update `composer check:consumer` and `composer check:catalog` as needed. |
| Workflow definition provider, replay fixture, recipe entrypoints, natural-task routing examples, expanded ability chains, or disallowed write defaults | Update the replay fixture and run `composer check:workflow-consumer`. |
| MCP public exposure, MCP risk metadata, annotations, or read/write server routing | Update the MCP exposure assertions and run `composer check:mcp-exposure`. |
| Provider helper behavior, third-party demo registration, or Npcink catalog projection defaults | Update the demo or projection assertions and run `composer check:provider-demo`. |
| WordPress.org review-risk surfaces such as admin assets, nonce handling, escaping, or forbidden include paths | Update static guards when possible and run `composer check:wporg`; before packaging, also run `composer check:plugin-package` in a WordPress environment. |
| Bootstrap assumptions, autoloading, or package-loading boundaries | Update `tests/bootstrap-performance.php` when the cold-load budget changes and run `composer perf:bootstrap`. |
| Performance-sensitive read chains, bounded aggregators, diagnostics, or cache behavior | Update `tests/performance-smoke.php` when the budgeted path changes and run `composer perf:smoke`. |
| Gutenberg composer routing, profile selection, read-only repair loops, or proposal-candidate quality gates | Update `tests/gutenberg-composer-pilot/` and run `composer pilot:gutenberg-composer`. |
| Real block-theme host discovery, routing, parsing, rendering, or no-mutation evidence | Run `WP_PATH=/path/to/wordpress composer smoke:block-theme-host-proof`; keep this explicit host gate outside the default source loop. |
| Public PHP class boundaries, bootstrap assumptions, or typed contracts | Run `composer analyse:phpstan` in addition to the source gate. |

## What Not To Test Here

Do not add broad tests for host-owned runtime concerns. This package does not
own model routing, prompt selection, workflow execution, approval storage, audit
truth, quota, billing, MCP gateway policy, cloud execution, or product-specific
Toolbox UX.

Do not create a full matrix for every ability and every input combination. For
large ability packs, test shared normalization and high-risk invariants once,
then add focused assertions only for ability-specific contracts that would break
real consumers.

Do not introduce PHPUnit, browser automation, or Docker-based E2E as the default
local loop unless the existing lightweight gate cannot express the contract
being protected. Heavy tests should remain explicit smoke or E2E commands.

## Adding A New Ability

Before adding or promoting a first-party ability, prove that the existing
ability chain cannot satisfy the host workflow through composition. If a new
ability is still justified, add the smallest reusable WordPress-only contract
and update the relevant contract, replay, smoke, boundary, and performance gates
before release.
