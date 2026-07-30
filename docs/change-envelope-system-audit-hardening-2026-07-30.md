# Change Envelope: System Audit Hardening

Date: 2026-07-30

## Scope

- Target repository: `npcink-abilities-toolkit` only.
- Focused modules: PHPStan entrypoint and findings, release source/package
  guards, WordPress runtime CI, permission-test defaults, status documentation,
  one evidence-triggered `Core_Write_Package` extraction, WordPress smoke
  lifecycle safety, uninstall cleanup, and post-merge repository hygiene.
- Intended change: make required checks and release artifacts trustworthy,
  extend runtime evidence to the minimum and current supported WordPress/PHP
  endpoints, remove stale operating state, and reduce one measured review
  bottleneck without changing public behavior.

## Explicit Non-Goals

- No new abilities, workflow recipes, model routing, prompts, runtime execution,
  approval storage, audit storage, billing, quota, Cloud behavior, or MCP
  gateway policy.
- No broad package rewrite or line-count-driven directory reorganization.
- No WordPress.org publication, release tag, version bump, or production
  deployment.
- No changes in consuming repositories.

## Contracts And Expected Files

- Public ability ids, schemas, annotations, permissions, risk metadata, workflow
  definitions, and host-governed write boundaries must remain unchanged.
- Expected areas: `composer.json`, `composer.lock`, `phpstan.neon.dist`,
  `includes/`, `tests/`, `scripts/`, `.github/workflows/`, release and status
  documentation, and `uninstall.php`.
- Must not change: workflow recipe manifests/fixtures, Cloud or Adapter
  contracts, product UI, translations, plugin version, `readme.txt` stable tag,
  or historical Git tags.

## Required Gates

- Focused regression tests for PHPStan startup, release source immutability,
  permission denial, uninstall cleanup, and smoke lifecycle restoration.
- `composer test:all`
- `composer analyse:phpstan`
- `composer check:boundary`
- `composer check:wporg`
- `git diff --check`
- Minimum and current WordPress smoke in CI; local real-site smoke only when a
  working site/database target is available.
- `WP_PATH=/path/to/wordpress composer release:verify` remains required for a
  later release candidate.

## Coordination And Rollback

- Cross-repository quality matrix: not required because public contracts and
  consuming repositories remain unchanged. Run it only if implementation
  unexpectedly crosses those boundaries.
- Rollback: revert the focused commit for the affected gate or extraction.
  Existing ability contracts and historical release tags remain untouched, so
  rollback does not require data migration or cross-repository coordination.
