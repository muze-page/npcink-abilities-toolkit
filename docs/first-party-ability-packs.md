# First-Party Ability Packs

This document groups the built-in abilities by product purpose. It does not
change runtime ownership. The current implementation remains in
`includes/Packages/*`, with sub-pack classifiers split into small
`Core_*_Pack_Classifier` classes so host package gating can evolve without
changing ability ids, schemas, or callbacks.

Recommended cross-pack compositions are documented in
[Workflow Recipes](workflow-recipes.md). Those recipes are host-side
consumption guidance, not runtime ownership in this package.

## Content Context Pack

Purpose: give agents and host runtimes structured WordPress content context
without requiring direct database or internal API access.

Risk: read.

Writable: no.

Host approval: no final commit approval required.

Primary consumers: Npcink AI, Npcink AI Toolbox, direct Abilities API clients,
and third-party hosts that need WordPress content discovery.

Representative abilities:

- `npcink-abilities-toolkit/site-info`
- `npcink-abilities-toolkit/list-post-types`
- `npcink-abilities-toolkit/list-taxonomies`
- `npcink-abilities-toolkit/count-posts`
- `npcink-abilities-toolkit/list-pages-tree`
- `npcink-abilities-toolkit/list-posts`
- `npcink-abilities-toolkit/get-post`
- `npcink-abilities-toolkit/get-post-context`
- `npcink-abilities-toolkit/get-content-inventory-health`
- `npcink-abilities-toolkit/get-nonproduction-content-inventory`
- `npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan`
- `npcink-abilities-toolkit/build-content-inventory-fix-plan`
- `npcink-abilities-toolkit/get-site-operations-dashboard`
- `npcink-abilities-toolkit/resolve-url-to-post`
- `npcink-abilities-toolkit/get-post-blocks`
- `npcink-abilities-toolkit/list-post-revisions`
- `npcink-abilities-toolkit/list-media`
- `npcink-abilities-toolkit/resolve-media-attachment-by-url`
- `npcink-abilities-toolkit/get-media-inventory-health`

`get-media-inventory-health` defaults to the existing date-descending order for
operator scans. Resumable Cloud media recognition may pass the bounded
`stable_order=id_asc` option so newly created attachments append after the
already scanned ID range instead of shifting page boundaries.

- `npcink-abilities-toolkit/inspect-media-asset`
- `npcink-abilities-toolkit/list-media-backups`
- `npcink-abilities-toolkit/build-media-derivative-cloud-request`
- `npcink-abilities-toolkit/build-media-optimization-plan`
- `npcink-abilities-toolkit/build-image-candidate-adoption-plan`
- `npcink-abilities-toolkit/build-media-rename-plan`
- `npcink-abilities-toolkit/get-media-cleanup-opportunities`
- `npcink-abilities-toolkit/build-media-inventory-fix-plan`
- `npcink-abilities-toolkit/get-taxonomy-inventory-health`
- `npcink-abilities-toolkit/get-taxonomy-consolidation-suggestions`
- `npcink-abilities-toolkit/suggest-post-taxonomy-terms`
- `npcink-abilities-toolkit/build-taxonomy-tag-review-set`
- `npcink-abilities-toolkit/propose-post-taxonomy-terms`
- `npcink-abilities-toolkit/list-terms`
- `npcink-abilities-toolkit/list-taxonomy-terms`
- `npcink-abilities-toolkit/list-categories`
- `npcink-abilities-toolkit/list-tags`
- `npcink-abilities-toolkit/get-term`
- `npcink-abilities-toolkit/list-users`
- `npcink-abilities-toolkit/list-comments`
- `npcink-abilities-toolkit/list-menus`
- `npcink-abilities-toolkit/get-menu`
- `npcink-abilities-toolkit/search-posts`
- `npcink-abilities-toolkit/search-post-meta`
- `npcink-abilities-toolkit/get-post-stats`
- `npcink-abilities-toolkit/list-revisions`
- `npcink-abilities-toolkit/get-post-meta`
- `npcink-abilities-toolkit/list-pages`
- `npcink-abilities-toolkit/get-page`
- `npcink-abilities-toolkit/inspect-page-structure`
- `npcink-abilities-toolkit/get-page-structure-health`

For media `format_attention` handling, keep inventory plans read-only and use
[Media Format Attention Boundary](media-format-attention-boundary.md) before
adding file-asset write actions.

## Publishing Pack

Purpose: expose generic WordPress publishing context and mutations through
read-only support helpers plus host-governed dry-run and commit contracts.

Risk: read for publishing support helpers; write or destructive for final
mutations.

Writable: read-support helpers are not writable; mutation abilities are writable.

Host approval: not required for read-support helpers; required for final commit.

Primary consumers: Npcink AI and other host runtimes that can provide caller
identity, approval, audit, quota, and idempotency context.

Representative write abilities:

- `npcink-abilities-toolkit/create-draft`
- `npcink-abilities-toolkit/update-post`
- `npcink-abilities-toolkit/set-post-seo-meta`
- `npcink-abilities-toolkit/patch-post-content`
- `npcink-abilities-toolkit/update-post-blocks`
- `npcink-abilities-toolkit/set-post-slug`
- `npcink-abilities-toolkit/set-post-author`
- `npcink-abilities-toolkit/set-post-template`
- `npcink-abilities-toolkit/set-post-format`
- `npcink-abilities-toolkit/create-term`
- `npcink-abilities-toolkit/update-term`
- `npcink-abilities-toolkit/set-post-terms`
- `npcink-abilities-toolkit/update-media-details`
- `npcink-abilities-toolkit/upload-media-from-url`
- `npcink-abilities-toolkit/optimize-media-asset`
- `npcink-abilities-toolkit/replace-media-file`
- `npcink-abilities-toolkit/restore-media-backup`
- `npcink-abilities-toolkit/rename-media-file`
- `npcink-abilities-toolkit/adopt-cloud-media-derivative`
- `npcink-abilities-toolkit/set-post-featured-image`
- `npcink-abilities-toolkit/schedule-post`
- `npcink-abilities-toolkit/publish-post`
- `npcink-abilities-toolkit/restore-post`

`npcink-abilities-toolkit/build-image-candidate-review-artifact` is the reusable
review artifact builder for already retrieved `image_candidate.v1` rows. It
returns `image_candidate_review.v1` plus lightweight recommendation projections
without searching image providers, generating images, importing media, or
writing featured images.

`npcink-abilities-toolkit/build-media-alt-caption-review-set` is the reusable
review artifact builder for supplied media metadata snapshots and optional
reviewed `image_context_evidence.v1`. It returns
`media_alt_caption_review_set.v1` without inspecting pixels, choosing providers,
creating proposals, running media derivatives, or writing media metadata. Each
candidate row includes deterministic quality guidance such as
`candidate_quality.score`, `candidate_quality.tier`, and
`candidate_quality.automation_recommendation` so consumers can prioritize local
preview, context-required, caption-only, and visual-evidence-needed rows without
treating those hints as write authorization. `local_preview_candidate_count` is
the emitted summary count; deprecated ready-for-handoff aliases are not emitted
by the P0 builder and must not be used as proposal or write authorization.

Accepted missing-ALT rows can be normalized through
`npcink-abilities-toolkit/build-media-alt-apply-plan`. The planner emits one
`update-media-details` dry-run action carrying `expected_current_alt`, explicit
operator visual confirmation, and a stable idempotency key. It never creates,
approves, or executes a Core proposal and does not authorize attachment writes.

`npcink-abilities-toolkit/build-taxonomy-tag-review-set` is the reusable
review artifact builder for existing WordPress category and tag candidates. It
returns `taxonomy_tag_review_set.v1` from supplied post context and optional
ranking evidence without creating terms, assigning terms, calling Cloud
runtime, creating proposals, or writing taxonomy metadata.

`npcink-abilities-toolkit/build-image-candidate-adoption-plan` is the reusable
Core handoff planner for one reviewed `image_candidate.v1`. It preserves source,
attribution, download tracking, filename suggestion, and asset-persistence
evidence while emitting dry-run actions for `upload-media-from-url`,
`update-media-details`, and optional `set-post-featured-image`. It does not
search image sources, generate images, import files, approve proposals, or
execute writes.

Media filename policy: `npcink-abilities-toolkit/upload-media-from-url` and
`npcink-abilities-toolkit/adopt-cloud-media-derivative` may accept an approved `file_name` for
new local media files. `npcink-abilities-toolkit/rename-media-file` may rename an existing
attachment main file within its current uploads directory after approval; use
`npcink-abilities-toolkit/build-media-rename-plan` so exact post-content references to the old
uploads URL are patched in the same governed proposal.
`npcink-abilities-toolkit/replace-media-file` and `npcink-abilities-toolkit/adopt-cloud-media-derivative`
also preview and commit exact post-content reference repairs for the old main
uploads URL and known intermediate-size image URLs while switching the
attachment pointer to the approved replacement file. Media optimization plans
pass reviewed post ids, post count, and replacement count as lightweight
expectations on `npcink-abilities-toolkit/adopt-cloud-media-derivative`; commit
recomputes repairs and blocks with a conflict if those reviewed targets drift.
The repair evidence keeps backward-compatible `replacement_count` and also
separates `replacement_rule_count`, `actual_replacement_count`, and
`unmatched_rules` so review UIs can explain overlap or no-op replacement rules.
`npcink-abilities-toolkit/restore-media-backup` uses the same repair evidence in reverse when
rolling an attachment back to its original file. Media replacement, Cloud
derivative adoption, and backup restore commits return a compact
`verification` summary with the current file, MIME type, post-reference repair
results, backup availability, and rollback availability.
`npcink-abilities-toolkit/inspect-media-asset` and
`npcink-abilities-toolkit/build-media-derivative-cloud-request` expose
`storage` preflight evidence. When an attachment appears to be OSS/CDN/object
storage backed, or when the local uploads file is unavailable, media file
replacement abilities fail closed unless a host storage adapter supplies
reviewed storage readiness. The storage evidence is a capability and drift
guard only; Toolkit does not store object-storage credentials, own provider
configuration, or purge CDN caches.
`npcink-abilities-toolkit/update-media-details` updates metadata only and must not physically
rename existing attachment files.

Representative read-only publishing support abilities:

- `npcink-abilities-toolkit/get-content-publishing-checklist`
- `npcink-abilities-toolkit/get-bulk-publishing-checklist`
- `npcink-abilities-toolkit/get-revision-change-risk-report`
- `npcink-abilities-toolkit/get-post-publish-risk-report`
- `npcink-abilities-toolkit/get-article-publish-preflight-context`
- `npcink-abilities-toolkit/get-publishing-calendar-context`

Representative destructive abilities:

- `npcink-abilities-toolkit/delete-term`
- `npcink-abilities-toolkit/merge-terms`
- `npcink-abilities-toolkit/bulk-update-post-terms`
- `npcink-abilities-toolkit/delete-media-permanently`
- `npcink-abilities-toolkit/trash-post`
- `npcink-abilities-toolkit/delete-post-permanently`

## Comment Compliance Pack

Purpose: support comment moderation, reply handoff, and batch review workflows
without moving workflow orchestration into this package.

Risk: read for suggestion and composition helpers; write/destructive for final
WordPress comment mutations.

Writable: suggestions are not writable; final comment actions are host-governed
write/destructive abilities.

Host approval: required for final approve, reply, spam, or trash actions.

Primary consumers: Npcink AI comment workflows, compliance helpers, and host
runtimes that need deterministic comment handoffs.

Representative abilities:

- `npcink-abilities-toolkit/list-comments`
- `npcink-abilities-toolkit/build-comment-moderation-suggest`
- `npcink-abilities-toolkit/compose-comment-moderation-result`
- `npcink-abilities-toolkit/build-comment-mention-reply-suggest`
- `npcink-abilities-toolkit/read-comment-trigger-queue`
- `npcink-abilities-toolkit/get-comment-queue-health`
- `npcink-abilities-toolkit/get-comment-action-priority-queue`
- `npcink-abilities-toolkit/get-comment-compliance-handoff`
- `npcink-abilities-toolkit/compose-comment-mention-reply-result`
- `npcink-abilities-toolkit/build-comment-moderation-batch-suggest`
- `npcink-abilities-toolkit/compose-comment-moderation-batch-result`
- `npcink-abilities-toolkit/approve-comment`
- `npcink-abilities-toolkit/reply-comment`
- `npcink-abilities-toolkit/spam-comment`
- `npcink-abilities-toolkit/trash-comment`

## Diagnostics Pack

Purpose: provide redacted WordPress-only diagnostics for agents and host
runtimes without exposing Npcink AI runtime, MCP, database, filesystem, or
secret state.

Risk: read.

Writable: no.

Host approval: no final commit approval required.

Primary consumers: direct Abilities API clients, Npcink AI, Toolbox support
flows, and local smoke checks.

Abilities:

- `npcink-abilities-toolkit/wp-diagnostics-summary`
- `npcink-abilities-toolkit/wp-ops-diagnostics-detail`

The detail ability is the support-facing follow-up surface for bounded plugin
rows, caller permissions, log severity summaries, optional structured log
contents, log source summaries with message fingerprints and safe PHAR basename
hints, top repeated log messages, cache/rewrite/HTTPS, database/cron/server
state, content type/role/widget/block-theme inventories, search/integration
hints, and SEO/security/performance summaries. Inactive plugin rows are omitted
by default and can be requested explicitly for deeper conflict diagnosis.

## Workflow Definition Pack

Purpose: expose read-only workflow recipe definitions for hosts that need
runtime discovery of recommended ability composition without turning this
package into a workflow engine.

Risk: read.

Writable: no.

Host approval: no final commit approval required.

Primary consumers: Npcink AI Core, Agent Gateway, MCP adapters, and other hosts
that need recipe discovery while keeping execution, approval, audit, quota, model
routing, and final writes in the host runtime.

Abilities:

- `npcink-abilities-toolkit/list-workflow-recipes`
- `npcink-abilities-toolkit/get-workflow-recipe`

## SEO/GEO Support Pack

Purpose: provide deterministic context, planning, and review helpers for SEO,
GEO, article optimization, media metadata, and article production workflows
without owning model routing or final publication policy.

Risk: read.

Writable: no for helpers in this pack. Applying changes belongs to the
Publishing Pack or a host workflow.

Host approval: no approval for context/suggestion helpers; approval is required
only when a host later executes write/destructive abilities.

Primary consumers: Npcink AI article workflows, content products, SEO/GEO
assistants, and host runtimes that compose suggestions with model output.

Representative abilities:

- `npcink-abilities-toolkit/propose-post-excerpt`
- `npcink-abilities-toolkit/resolve-post-metadata-plan`
- `npcink-abilities-toolkit/resolve-internal-link-targets`
- `npcink-abilities-toolkit/get-internal-link-opportunity-report`
- `npcink-abilities-toolkit/get-content-refresh-opportunities`
- `npcink-abilities-toolkit/get-internal-link-graph-health`
- `npcink-abilities-toolkit/get-post-seo-geo-readiness`
- `npcink-abilities-toolkit/get-site-topic-coverage-report`
- `npcink-abilities-toolkit/get-seo-geo-gap-report`
- `npcink-abilities-toolkit/get-site-style-baseline`
- `npcink-abilities-toolkit/build-article-workflow-context`
- `npcink-abilities-toolkit/get-old-article-refresh-context`
- `npcink-abilities-toolkit/build-inline-image-blocks`
- `npcink-abilities-toolkit/build-media-seo-assets`
- `npcink-abilities-toolkit/geo-analyze`

`resolve-internal-link-targets` owns reusable internal-link candidate assembly:
it can combine bounded local published-post search with host-supplied related
content evidence and returns `internal_link_candidates.v1` for review surfaces.
It does not own provider search, vector indexing, link insertion, or
post-content patching.
- `npcink-abilities-toolkit/optimize-media-metadata`
- `npcink-abilities-toolkit/position-inline-image-blocks`
- `npcink-abilities-toolkit/build-article-optimization-report`
- `npcink-abilities-toolkit/seo-report-context`
- `npcink-abilities-toolkit/read-post-optimization-context`
- `npcink-abilities-toolkit/build-article-single-optimization-suggest`
- `npcink-abilities-toolkit/build-article-optimization-apply-plan`
- `npcink-abilities-toolkit/build-content-metadata-apply-plan`
- `npcink-abilities-toolkit/compose-article-optimization-apply-result`
- `npcink-abilities-toolkit/extract-reference-post-style`
- `npcink-abilities-toolkit/extract-style-baseline`
- `npcink-abilities-toolkit/build-article-production-fingerprint`
- `npcink-abilities-toolkit/check-article-production-duplicate`
- `npcink-abilities-toolkit/review-article-output-light`
- `npcink-abilities-toolkit/compose-article-production-result`
- `npcink-abilities-toolkit/compose-article-draft-result`
- `npcink-abilities-toolkit/resolve-article-publication-decision`
- `npcink-abilities-toolkit/build-article-style-profile`
