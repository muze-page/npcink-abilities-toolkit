=== Npcink Abilities Toolkit ===
Contributors: muze233
Tags: abilities api, agents, ai, automation
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.5.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose and inspect WordPress Abilities API capabilities for AI hosts and clients, without running models or writing content.

== Description ==

Npcink Abilities Toolkit helps a WordPress site expose, review, and safely inspect Abilities API capabilities for AI hosts and clients.

The admin page is built for site operators first. It shows whether the site's ability package is working, which abilities are available, which abilities are read-only, and which write-like abilities require host approval.

For developers and host runtimes, the plugin also provides ability registration helpers, category helpers, schema normalization, annotation normalization, REST discovery values, and optional canonical projection for Npcink AI when Npcink AI is installed.

It can be used by any WordPress plugin or client that consumes the WordPress Abilities API. Npcink AI is one optional consumer, not the owner of this plugin.

It does not run AI models, execute workflows, route prompts, contact model providers, manage billing or quotas, own MCP governance, or approve final WordPress writes.

Read-only host composition recipe metadata may document how hosts can compose abilities, but those records do not run queues, schedule jobs, execute workflows, or create a second registry.

Host composition recipe metadata is available through read-only helpers and Abilities API discovery abilities for hosts that need catalog discovery without execution ownership.

Bundled starter locale files are limited to translations intentionally maintained in this repository. Incomplete bundled locale packs are removed until they can be maintained as complete starter translations. WordPress.org directory translations are managed separately through translate.wordpress.org/GlotPress and are not runtime authority owned by this plugin.

== External Services and Remote Requests ==

This plugin does not automatically contact Npcink AI, model providers, analytics services, tracking services, or cloud services.

Some abilities can prepare request payloads for a separate host or cloud add-on, but this plugin does not send those payloads to an external service by itself.

The `npcink-abilities-toolkit/upload-media-from-url` ability is inert as a dry-run preview unless a separate authenticated host supplies approval context and commits the operation. When an approved host runtime commits that ability, WordPress sends an HTTP request to the caller-provided URL in order to fetch the media file for the local media library. The remote endpoint is chosen by the caller, not by this plugin. This plugin stores no provider credentials or remote-service configuration for that ability. This is not provider routing, cloud execution, or stored external-service configuration.

== Requirements ==

* WordPress 6.9 or later. WordPress 6.9 introduced the Abilities API used by this plugin.
* PHP 8.0 or later.
* The WordPress Abilities API REST routes must be available before third-party
  provider plugins or external clients can discover and run abilities.

== Public API ==

* `npcink_abilities_toolkit_register_category( $category_id, $args )`
* `npcink_abilities_toolkit_register_readonly( $ability_id, $definition )`
* `npcink_abilities_toolkit_register_write_proposal( $ability_id, $definition )`
* `npcink_abilities_toolkit_normalize_schema( $schema, $default_type )`
* `npcink_abilities_toolkit_normalize_annotations( $annotations, $risk_level )`
* `npcink_abilities_toolkit_get_registered()`
* `npcink_abilities_toolkit_get_workflow_definitions()`
* `npcink_abilities_toolkit_get_workflow_definition( $recipe_id )`

The workflow definition helpers return read-only recipe metadata for host-side composition. They are not a workflow registry, execution engine, scheduler, approval store, audit store, model router, prompt registry, or final write authority.

== Third-Party Integration Quickstart ==

Provider plugins should wait until `plugins_loaded`, check that the public
helper exists, and then register their abilities through the helper functions:

`if ( function_exists( 'npcink_abilities_toolkit_register_readonly' ) ) { npcink_abilities_toolkit_register_readonly( 'acme/site-summary', $definition ); }`

Do not include files from this plugin's `includes/` directory or instantiate
classes in the `Npcink_Abilities_Toolkit` namespace. Those are implementation
details.

Use `npcink_abilities_toolkit_register_readonly()` for read-only context and
diagnostic abilities. Use `npcink_abilities_toolkit_register_write_proposal()`
only when the callback returns a proposal, preview, diff, or handoff payload.
Third-party provider callbacks should not perform final host-governed commits.
Approval, audit, quota, and final write authorization belong to the consuming
host runtime.

REST clients should first discover the catalog through:

* `/wp-json/wp-abilities/v1/categories`
* `/wp-json/wp-abilities/v1/abilities`
* `/wp-json/wp-abilities/v1/abilities/{namespace}/{name}/run`
* `/wp-json/npcink-abilities-toolkit/v1/contract`

The contract endpoint is a compatibility and boundary discovery endpoint for
authenticated host runtimes. It does not replace the WordPress Abilities API
catalog and does not run abilities.

Full provider examples and REST client notes are maintained in the public
repository:

`https://github.com/muze-page/npcink-abilities-toolkit`

If the `wp-abilities/v1` REST routes are missing, enable the WordPress
Abilities API baseline or compatibility plugin before connecting third-party
providers or clients.

== Admin Page ==

After activation with a Npcink AI host plugin, open Npcink AI -> AI Ability Set in wp-admin. When this standalone package is installed without a Npcink AI host menu, open Tools -> AI Ability Set instead.

The page is designed for site operators first: it shows site ability status, groups available abilities with plain labels and risk posture, and can run two official read-only checks: site info and bounded redacted diagnostics summary. The Checks tab explains what each check proves and what it does not prove before it runs. Check results are shown as a plain summary table, with raw JSON kept behind a support disclosure. Developer Access keeps copyable REST endpoint values, raw discovery fetches, and ability ID export available for host/client setup. It does not run showcase workflows, model calls, write abilities, approval flows, or demo abilities. The Tools -> AI Ability Set page remains the standalone surface when no Npcink AI host menu is active; host detection must not create approval, quota, audit, or workflow control-plane behavior in this plugin.

== Frequently Asked Questions ==

= Does this plugin run AI models? =

No. Npcink Abilities Toolkit exposes WordPress abilities and support information through the WordPress Abilities API. Model routing, prompt selection, hosted runtime execution, and workflow execution belong to a separate host product or client.

= Will this plugin change my posts, media, terms, comments, or settings by itself? =

No. The admin page checks are read-only. Some built-in abilities describe write-like or destructive operations, but final commits require a host runtime with its own approval, authorization, and audit layer.

= Do I need Npcink AI to use this plugin? =

No. Npcink AI is an optional consumer. The plugin can also be used by other plugins or clients that consume the WordPress Abilities API.

= What do the Safe Checks prove? =

Site Info proves that an authorized ability client can read basic WordPress site information. Redacted Diagnostics proves that the site can return a support-friendly environment summary with sensitive fields omitted. These checks do not call models, generate content, contact external services, or fix configuration automatically.

= Does Redacted Diagnostics expose secrets? =

No. The diagnostics summary intentionally omits Npcink AI settings, MCP settings, API keys, database names, table prefixes, filesystem paths, error logs, and external HTTP probes.

= What should I do if abilities are not visible to a host product or AI client? =

Open Tools -> AI Ability Set, or Npcink AI -> AI Ability Set when a Npcink AI host menu is present. Use the Checks tab to confirm safe read-only responses, then use Available Abilities to review what the site exposes. Developers and host products can use Developer Access for REST endpoint values and raw discovery responses.

= What if the wp-abilities/v1 REST routes are missing? =

The WordPress Abilities API routes must be available before clients can discover and run abilities. Enable the WordPress Abilities API baseline or compatibility plugin for the target site.

== Screenshots ==

1. Site ability status overview with available ability count, write safeguards, host detection, and next actions.
2. Available Abilities catalog with filters, risk grouping, availability, and technical details for support.
3. Safe Checks tab explaining what each check proves before showing summary results and raw response support details.
4. Developer Access tab with copyable REST endpoint values, raw discovery fetches, and ability ID export.

== Built-In Abilities ==

The plugin includes migrated low-risk WordPress read abilities, deterministic comment helpers, and host-governed WordPress write/destructive abilities using canonical `npcink-abilities-toolkit/*` ids.

It also includes `npcink-abilities-toolkit/wp-diagnostics-summary`, a redacted WordPress-only diagnostics summary for Abilities API clients. This summary intentionally omits Npcink AI settings, MCP settings, API keys, database names, table prefixes, filesystem paths, error logs, and external HTTP probes.

It also includes `npcink-abilities-toolkit/search-posts` and `npcink-abilities-toolkit/search-post-meta`, bounded local WordPress search helpers for keyword and explicit post-meta discovery. These are read-only helpers and do not call external search indexes or mutate content.

It also includes `npcink-abilities-toolkit/list-workflow-recipes` and `npcink-abilities-toolkit/get-workflow-recipe`, read-only host composition recipe metadata discovery abilities. These expose metadata only and do not execute workflow runtime behavior.

Core governance handoff docs include a catalog snapshot, permission matrix, and schema boundary audit for hosts that consume this plugin through `npcink-ai-core`.

== Developer Verification ==

For the default local source gate, run:

`composer test:all`

When the plugin is installed in a local WordPress site, run:

`WP_PATH=/path/to/wordpress composer smoke:wp`

For isolated bounded-chain performance validation, run:

`composer perf:smoke`

== Changelog ==

= 0.5.5 =

* Isolate the packaged Plugin Check regression test from inherited local WordPress CLI runtime settings.

= 0.5.4 =

* Added bounded media backup retention cleanup and preserved exact-manifest batch backups until explicit operator confirmation.
* Aligned cleanup cron registration with the enabled write package and cleared it during uninstall.
* Required semantic body evidence for internal-link candidates and kept final application in the visible editor.
* Added canonical media fingerprints and replacement lineage to governed media operations.

= 0.5.3 =

* Corrected the minimum WordPress version to 6.9 and added a real minimum-version release smoke.
* Made Toolkit the canonical owner of reusable static workflow definitions without adding workflow runtime state.
* Replaced the raw ability-count gate with representative contract assertions and documented WordPress Core convergence rules.
* Made write and destructive callbacks fail safe unless commit intent is explicit and host-governed.
* Restricted post-meta reads to explicit non-sensitive keys and required host allowlists for setting patch targets.
* Validated remote media before uploads, removed the obsolete in-memory fallback, and redacted reversible setting fragments.
* Added internal autoloading, lazy package construction, and a cold-bootstrap performance budget.
* Removed the incomplete bundled Traditional Chinese starter locale until it can be maintained as a complete pack.

= 0.5.2 =

* Added read-only review artifacts for image candidates, internal link candidates, taxonomy suggestions, and comment reply suggestions.
* Kept candidate review helpers suggestion-only and host-governed; they do not create proposals, approve work, execute writes, or contact external providers.
* Expanded acceptance contracts and first-party pack documentation for the new handoff artifacts.

= 0.5.1 =

* Added Composer dependency advisory audit to the default local and CI source gate.
* Aligned CI and PHPStan with the package PHP 8.0 runtime floor.
* Published the canonical public GitHub repository and documented the post-publication gate baseline.
* Upgraded GitHub Actions checkout to the Node 24-compatible `actions/checkout@v5`.

= 0.5.0 =

* Improved the admin page as a connection and discovery surface with clearer package status, catalog navigation, copyable REST endpoints, and two bounded read-only checks.
* Added bundled translation templates and starter locale packs for the admin connection/discovery surface, API ability labels/descriptions, and common runtime error messages.
* Removed development demo ability controls from the admin page and kept showcase, model-call, write, and workflow execution outside this package surface.
* Renamed nonproduction cleanup abilities and media cleanup inputs to avoid public `test` terminology in released ability ids and schema fields.
* Added taxonomy assignment proposal support and strengthened Core consumer handoff checks for harvested workflow surfaces.
* Hardened media replacement and Cloud derivative adoption previews so approved replacements can repair exact post-content media URLs, including old intermediate-size URLs.
* Documented the foundation-layer testing strategy and default local source gate.

= 0.4.0 =

* Added read-only host composition recipe metadata discovery abilities and public PHP helpers.
* Added Core governance handoff documentation, a catalog snapshot fixture, a permission matrix, and a schema boundary audit.
* Hardened write-like contracts with `requires_approval`, explicit dry-run and commit defaults, and bounded idempotency keys.
* Expanded REST verification coverage for governance metadata and schemas.
* Added a consumer example for preparing Core proposal payloads from discovered ability contracts.

= 0.3.0 =

* Added package and sub-pack filters so hosts can keep a full catalog by default or opt into a light `core_wordpress_read` profile.
* Kept public third-party helpers read-only/write-proposal oriented and documented that final commit authorization belongs to the host runtime.
* Made Npcink AI catalog projection thin by default and added a projection-row filter for host-owned policy expansion.
* Established `npcink-abilities-toolkit/*` as the canonical id namespace for abilities owned by this plugin.
* Added explicit read/comment sub-pack maps as the split point for future source-file extraction.
* Verified Npcink AI catalog compatibility against a WordPress site.

= 0.2.0 =

* Stabilized the public helper contract and ability metadata rules.
* Documented host-governed write/destructive semantics and the Npcink AI integration boundary.
* Added first-party ability pack grouping and 0.2 candidate verification evidence.
* Strengthened lightweight tests for schema controls, Npcink catalog projection, provider defaults, and invalid ability ids.
* Verified WordPress REST coverage and Npcink AI consumer split-boundary checks.

= 0.1.0 =

* Initial standalone release.
* Added migrated WordPress read abilities, deterministic comment helpers, host-governed write/destructive abilities, and standalone redacted WordPress diagnostics.
