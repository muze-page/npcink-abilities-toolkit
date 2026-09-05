# Changelog

## 0.5.5 - 2026-09-05

- Isolated the packaged Plugin Check regression test from inherited `WP_CLI_PHP`, error-reporting, and database socket settings so the cross-repository release gate remains deterministic.

## 0.5.4 - 2026-09-04

- Added bounded media backup retention cleanup with an explicit manual-confirmation exception for exact-manifest batches.
- Aligned cleanup scheduling with the enabled package profile and cleared the maintenance cron during uninstall.
- Hardened internal-link candidates to require semantic body evidence and preserve a visible editor handoff.
- Added media fingerprint and replacement-lineage evidence to governed media operations.

## 0.5.3 - 2026-07-10

- Corrected the minimum WordPress version to 6.9, where the Abilities API was
  introduced, while keeping WordPress 7.0 as the current tested-up-to line.
- Replaced the ability-count floor in the official-stack gate with explicit
  representative contracts so quality checks do not reward catalog growth.
- Recorded Toolkit as the canonical owner of reusable static workflow
  definitions while keeping execution, governance, and runtime state in hosts.
- Made built-in write and destructive callbacks fail safe when `commit=false`
  or `dry_run=true`, including conflicting control values.
- Restricted post-meta reads to explicit non-sensitive keys and removed
  unscoped fallbacks that exposed every stored post-meta value.
- Required hosts to explicitly allowlist non-sensitive targets before
  `patch-setting-value` can preview or commit a change, and replaced reversible
  setting fragments with bounded hashes and lengths.
- Validated remote media in a temporary file before copying it into uploads,
  and removed the obsolete in-memory upload fallback.
- Added namespace autoloading, lazy package construction, and a cold-bootstrap
  performance budget to reduce unconditional plugin load cost.
- Updated ability, security, schema, performance, testing, and local WP-CLI
  documentation for the hardened contracts and current verification baseline.
- Removed the incomplete bundled Traditional Chinese (`zh_TW`) starter locale
  pack from the source tree until it can be maintained as a complete
  translation set.
- Documented that bundled starter locales are repository-maintained runtime
  files, while WordPress.org directory translations remain managed separately
  through translate.wordpress.org/GlotPress.

## 0.5.2 - 2026-06-22

- Reworked the wp-admin surface around ordinary site operators: renamed the
  entry point to Site AI Abilities, added overview, available abilities, checks,
  and developer access tabs, and kept developer details available without making
  them the default experience.
- Added user-facing safe check summaries with purpose tables and collapsed raw
  JSON so site owners can understand what the checks prove without reading API
  payloads first.
- Refreshed the Chinese admin translation catalog for the updated settings page,
  check result summaries, plugin action links, and WordPress.org listing copy.
- Prepared WordPress.org listing content, FAQ, screenshots, banner/icon notes,
  and release assets for the operator-focused positioning.
- Added read-only review artifacts for image candidates, internal link
  candidates, taxonomy suggestions, and comment reply suggestions while keeping
  final writes host-governed.
- Published WordPress.org SVN revision 3581125 after `composer release:verify`
  completed with Plugin Check reporting no errors.

## 0.5.1 - 2026-06-07

- Added Composer dependency advisory audit to the default `composer test:all`
  source gate.
- Aligned GitHub Actions and PHPStan with the package PHP 8.0 runtime floor.
- Published the canonical public GitHub repository and documented the
  post-publication gate baseline.
- Upgraded GitHub Actions checkout to the Node 24-compatible
  `actions/checkout@v5`.

## 0.5.0 - 2026-06-06

- Improved the admin page as a connection and discovery surface with clearer package status, catalog navigation, copyable REST endpoints, and two bounded read-only checks.
- Added bundled translation templates and eight starter locale packs for the admin connection/discovery surface, API ability labels/descriptions, and common runtime error messages.
- Removed development demo ability controls from the admin page and kept showcase, model-call, write, and workflow execution outside this package surface.
- Renamed nonproduction cleanup abilities and media cleanup inputs to avoid public `test` terminology in released ability ids and schema fields.
- Updated `npcink-abilities-toolkit/adopt-cloud-media-derivative` and `npcink-abilities-toolkit/replace-media-file` to preview and commit exact post-content media URL repairs, including old intermediate-size image URLs, when an approved replacement switches an attachment to a new local file.
- Added `npcink-abilities-toolkit/propose-post-taxonomy-terms`, a deterministic taxonomy assignment proposal helper that targets `npcink-abilities-toolkit/set-post-terms` without mutating posts or creating terms.
- Added a 0.5 verification note that records the taxonomy terms Core consumer proof and keeps the package in freeze/observe mode until a concrete workflow gap appears.
- Added a Npcink AI main repo harvest checkpoint that maps content/media/comment/batch cleanup signals to existing abilities and confirms that no new ability batch should start from candidate lists alone.
- Expanded the Core consumer handoff check to assert the five harvested surfaces and their corresponding host-governed write targets expose Abilities API discovery metadata, schemas, dry-run controls, and approval metadata.
- Documented the foundation-layer testing strategy and clarified that `composer test:all` is the default local source gate for contract, governance, performance, and lightweight regression coverage.

## 0.4.0 - 2026-05-30

- Added a Core governance catalog snapshot fixture covering draft creation, SEO metadata, comment approval, and workflow definition discovery contracts.
- Added Core consumer handoff and catalog audit checks for release-candidate governance validation.
- Added permission matrix and schema boundary audit documentation for first-party write/destructive abilities.
- Added a Core governance consumer example that discovers ability contracts and prepares a proposal payload without moving Core governance into this package.
- Hardened write-like contract metadata with `requires_approval`, dry-run and commit defaults, and bounded idempotency keys.
- Expanded WordPress smoke coverage to assert REST-exposed governance metadata and schemas for Core handoff abilities.
- Tightened `set-post-seo-meta` so omitted metadata fields do not overwrite existing values, and added permission coverage for comment approval dry-runs.
- Added read-only workflow definition discovery through PHP helpers and Abilities API abilities without introducing workflow runtime ownership.
- Added host profile guidance for full governed hosts and light core-read integrations.
- Added workflow definition contract tests that keep the replay fixture aligned with the production provider and reject runtime/governance fields.
- Split the standalone WordPress diagnostics read ability definition into a dedicated read definitions provider while preserving registration order and behavior.
- Split built-in comment helper ability definitions into a dedicated comment definitions provider while preserving callback ownership, sub-pack classification, and registration behavior.
- Split generic `core_wordpress_read` ability definitions into a dedicated read definitions provider while preserving callback ownership, sub-pack classification, and registration order.

## 0.3.0 - 2026-05-29

- Added the 0.3 ability acceptance matrix, agent workflow validation plan, and stabilization scope documents.
- Added package and sub-pack filters for host-selectable built-in package composition while preserving full default registration.
- Added explicit read/comment sub-pack maps as the stable entry point for future definition-provider extraction.
- Kept public third-party helpers read-only/write-proposal oriented and documented that final commit authorization belongs to host runtimes.
- Made Npcink AI catalog projection thin by default and added a single projected-row filter for host-owned catalog expansion.
- Established `npcink-abilities-toolkit/*` as the canonical id namespace for abilities owned by this plugin.
- Verified Npcink AI local Catalog compatibility: authenticated catalog page returned HTTP 200, the capabilities endpoint returned 198 entries, and projected rows omitted host-owned runtime policy fields.
- Added workflow recipe guidance that documents host-side ability composition without moving workflow runtime ownership into this package.
- Added read-only workflow context bundles for article publish preflight, old article refresh discovery, and comment compliance handoff.
- Added short-TTL read caching for selected bounded reports and a `composer perf:smoke` performance smoke command.
- Expanded WordPress smoke coverage from individual ability execution to the publish preflight, content refresh discovery, and comment compliance workflow chains.
- Added `npcink-abilities-toolkit/get-post-context`, a read-only post context bundle for agent workflows.
- Added `npcink-abilities-toolkit/get-content-publishing-checklist`, a read-only pre-publish readiness checklist.
- Added `npcink-abilities-toolkit/get-content-inventory-health`, a bounded read-only inventory health scan.
- Added `npcink-abilities-toolkit/get-bulk-publishing-checklist`, a batched wrapper around the publishing checklist.
- Added `npcink-abilities-toolkit/get-internal-link-opportunity-report`, a post-id based internal link opportunity report.
- Added `npcink-abilities-toolkit/get-media-inventory-health`, a bounded read-only media metadata health scan.
- Added `npcink-abilities-toolkit/get-post-seo-geo-readiness`, a deterministic single-post SEO/GEO readiness snapshot.
- Added `npcink-abilities-toolkit/get-site-topic-coverage-report`, a bounded topic coverage summary for site content.
- Added `npcink-abilities-toolkit/get-taxonomy-inventory-health`, a bounded read-only taxonomy term governance scan.
- Added `npcink-abilities-toolkit/get-revision-change-risk-report`, a read-only revision change-risk summary for pre-write review.
- Added `npcink-abilities-toolkit/get-comment-queue-health`, a read-only moderation queue health summary.
- Added `npcink-abilities-toolkit/get-site-operations-dashboard`, a read-only site operations summary for Agent triage.
- Added `npcink-abilities-toolkit/get-post-publish-risk-report`, a read-only per-post publish risk report.
- Added `npcink-abilities-toolkit/get-content-refresh-opportunities`, a read-only refresh opportunity scanner.
- Added `npcink-abilities-toolkit/get-internal-link-graph-health`, a bounded internal-link graph health report.
- Added `npcink-abilities-toolkit/get-media-cleanup-opportunities`, a read-only media cleanup opportunity scanner.
- Added `npcink-abilities-toolkit/get-taxonomy-consolidation-suggestions`, a read-only taxonomy consolidation suggestion report.
- Added `npcink-abilities-toolkit/get-comment-action-priority-queue`, a prioritized read-only comment handoff queue.
- Added `npcink-abilities-toolkit/get-page-structure-health`, a read-only page structure health report.
- Added `npcink-abilities-toolkit/get-seo-geo-gap-report`, a read-only SEO/GEO gap report.
- Added `npcink-abilities-toolkit/get-site-style-baseline`, a read-only site style baseline wrapper.
- Added `npcink-abilities-toolkit/build-article-workflow-context`, a read-only article workflow context bundle.
- Added `npcink-abilities-toolkit/get-publishing-calendar-context`, a read-only publishing calendar context report.
- Updated first-party pack documentation, migration inventory, smoke coverage, and lightweight tests for the new abilities.

## 0.2.0 - 2026-05-28

- Stabilized the public helper contract and documented parameters, defaults, failure modes, and examples.
- Documented the ability contract for ids, categories, schemas, annotations, risk levels, scopes, Npcink AI metadata, MCP metadata, deprecation, and successor fields.
- Added host-governed write/destructive semantics for `dry_run`, `commit`, `idempotency_key`, `requires_confirm`, `preview`, and `commit_required`.
- Kept third-party public registration limited to category, readonly, write-proposal, schema/annotation normalization, and registered ability inspection helpers.
- Added first-party ability pack grouping for content context, publishing, comment compliance, diagnostics, and SEO/GEO support.
- Added Npcink AI consumer verification evidence, including duplicate-id audit expectations and `wp_ability` projection checks.
- Expanded lightweight tests for write controls, output schema controls, invalid ability ids, provider projection defaults, and Npcink catalog projection behavior.
- Verified Local WP smoke coverage and recorded 0.2 candidate evidence.

## 0.1.0 - 2026-05-28

- Established Npcink Abilities Toolkit as a standalone WordPress Abilities API capability-package plugin.
- Added public helpers for category registration, readonly abilities, write-proposal abilities, schema normalization, annotation normalization, and registered ability inspection.
- Added the migrated WordPress read-only package: `site-info`, `list-post-types`, `list-taxonomies`, `count-posts`, `list-pages-tree`, `list-posts`, `get-post`, `resolve-url-to-post`, `get-post-blocks`, `list-post-revisions`, `list-media`, `list-terms`, `list-taxonomy-terms`, `list-categories`, `list-tags`, `get-term`, `propose-post-excerpt`, `list-users`, `list-comments`, `list-menus`, `get-menu`, `search-posts`, `get-post-stats`, `list-revisions`, `get-post-meta`, `list-pages`, `get-page`, and `inspect-page-structure`.
- Added the migrated deterministic comment helper package: `build-comment-moderation-suggest`, `compose-comment-moderation-result`, `build-comment-mention-reply-suggest`, `read-comment-trigger-queue`, `compose-comment-mention-reply-result`, `build-comment-moderation-batch-suggest`, and `compose-comment-moderation-batch-result`.
- Added migrated host-governed WordPress write and destructive packages with dry-run defaults and approval-context commit gating.
- Added standalone redacted WordPress diagnostics ability: `npcink-abilities-toolkit/wp-diagnostics-summary`.
- Added a wp-admin test page under Tools -> Abilities API Packages.
- Added environment checks for Abilities API functions, REST routes, REST nonce usage, and Magick App Key non-usage.
- Added optional compatibility projection into the Npcink AI catalog for provider abilities.
- Added integration rules for optional Npcink AI consumption and a 0.1 public API freeze document.
- Added a WP-CLI smoke test for real WordPress environments.
- Added lightweight regression tests and PHP syntax linting.
