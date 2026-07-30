# Structural Split Plan

Status: paused; resume only from concrete change evidence.
Date: 2026-07-11.

This plan reduces review cost and change collisions without combining structural
moves with ability changes. Every slice must preserve public ability ids,
schemas, annotations, callbacks, lazy loading, dry-run defaults, and host-owned
approval and final authorization.

## Stabilization Checkpoint

The first two slices established the write-trait pattern and reduced
`Core_Write_Package` from 8,183 to 7,485 lines without changing its public
contracts. Source gates, PHPStan, bootstrap performance, real WordPress smoke,
and the real block-theme host proof passed after those moves.

The remaining sequence is intentionally paused after the 0.5.3 release. That
release hardened remote-media temporary-file validation, upload handling, and
write safety. Moving the media lifecycle immediately after that hardening would
add structural risk without a current consumer defect, feature requirement, or
measured review bottleneck. File size alone is not sufficient evidence to
resume the refactor.

Keep issue #89 open as an evidence-triggered maintenance record. Resume only
when at least one of these conditions is observed:

- repeated pull requests change the same responsibility and cause review or
  merge conflicts;
- a concrete bug or approved feature must cross unrelated responsibilities in
  one oversized module;
- security review cannot isolate remote input, file lifecycle, rollback, or
  reference-repair behavior;
- tests cannot independently prove the responsibility being changed;
- a maintainer or agent repeatedly modifies unrelated behavior because the
  current ownership boundary is unclear.

Do not resume merely to reduce line counts, complete this sequence, or make the
directory tree more symmetrical. During the pause, prioritize release
stability and evidence from Core, Adapter, Toolbox, and real WordPress hosts.

## Baseline Inventory

The first inventory measured the largest PHP files before the initial slice:

| File | Baseline lines | Primary responsibility |
| --- | ---: | --- |
| `includes/Packages/Core_Write_Package.php` | 8,183 | Write definitions, callbacks, shared guards, and media operations. |
| `includes/Packages/Read_Traits/Media_Read_Methods.php` | 7,771 | Media analysis and planning reads. |
| `tests/run.php` | 7,761 | Source-level contract and behavior assertions. |
| `includes/Packages/Core_Read_Package.php` | 5,680 | Read definitions plus shared read helpers. |
| `includes/Packages/Read_Traits/Page_Pattern_Read_Methods.php` | 4,599 | Page pattern composition and review. |
| `includes/Packages/Read_Traits/Block_Theme_Read_Methods.php` | 3,196 | Block-theme inspection and planning. |

Line count is a triage signal, not an architectural goal. Split only when a
cohesive responsibility can move without changing its public contract.

## Accepted Sequence

1. **Post attribute writes — completed in the first slice.**
   `set-post-slug`, `set-post-author`, `set-post-template`, and
   `set-post-format` live in `Post_Attribute_Write_Methods`. The owning class
   still registers the definitions and supplies shared governance helpers.
2. **Site Editor and Gutenberg writes — completed in the second slice.**
   `update-post-blocks`, `update-template-blocks`, `upsert-template-blocks`, and
   `update-template-part-blocks` now use implementations from
   `Site_Editor_Write_Methods`. The trait also owns their private block-document
   and template lookup helpers. `Core_Write_Package` remains the definition and
   composition owner, and the extraction preserves host-governed dry-run and
   final authorization behavior.
3. **Media write lifecycle — one reference-discovery slice completed; remaining
   work deferred pending independent evidence.** Evaluate remote intake,
   derivative materialization, file replacement/rollback, and reference repair
   independently. These paths have different security and rollback risks and
   must not move as one campaign.
4. **Definition providers — deferred pending callback pressure.** Move large
   read/write definition arrays only when a concrete definition change is made
   harder by current ownership and callback ownership is already stable.
   Definitions must continue to bind to the same object callbacks and metadata.
5. **Test suites — deferred pending test-maintenance pressure.** Split
   `tests/run.php` by contract surface only when test changes show repeated
   collision or isolation problems. Preserve a single default `composer test`
   entrypoint and aggregate assertion result.

Do not execute the remaining sequence automatically. When evidence justifies a
resume, open one focused pull request for the affected responsibility, verify
it, and return to stabilization unless the next responsibility has independent
evidence of its own.

## 2026-07-14 Evidence-Triggered Slice

Since 2026-06-01, non-merge history records 54 commits changing
`Core_Read_Package.php` and 31 changing `Media_Read_Methods.php`. That
concentrated definition and callback pressure satisfies the repeated-change
trigger for one narrow maintenance slice.

This slice resumes only the media governance read definition provider:
`Core_Read_Package` remains the composition and callback owner, while the 23
existing media definitions move unchanged to a stateless provider. Media method
implementations, ability contracts, ordering, host governance, and runtime
ownership do not move. This evidence does not unpause the broader structural
sequence; every later slice still requires its own concrete trigger.

The ALT/caption callback family has independent evidence. Since 2026-06-01,
four non-merge commits changed that concentrated chain: the initial review-set
ability, deterministic candidate scoring, preview-only quality guidance, and
the governed missing-ALT apply contract. Those changes repeatedly crossed the
same two public callbacks and their private candidate-quality helpers inside
the broader media trait.

The second slice therefore unfreezes only
`Media_Alt_Caption_Read_Methods`: `build_media_alt_caption_review_set`,
`build_media_alt_apply_plan`, and their 29 `media_alt_caption_*` private helpers
move together without wrappers. Other media methods, media definitions,
ability ids, schemas, callbacks, permissions, outputs, and runtime ownership do
not move. This evidence does not authorize another media-method extraction.

## 2026-07-30 Media Reference Discovery Slice

Recent media integrity and derivative-adoption changes repeatedly crossed the
same content-reference discovery helpers in `Core_Write_Package`. That satisfies
the repeated-change and security-isolation triggers for one bounded write-side
slice.

`Media_Reference_Discovery_Write_Methods` now owns only the private helpers that
derive old-to-new URL/file pairs, discover bounded candidate posts, and build
search needles. `Core_Write_Package` remains the ability definition, callback,
permission, mutation, rollback, and verification owner. No public ability id,
schema, annotation, callback, dry-run default, or final authorization changes.
The broader media lifecycle remains paused and requires independent evidence
before another extraction.

## Gate Per Slice

Each extraction must pass:

```bash
composer test:all
composer analyse:phpstan
composer check:boundary
composer perf:bootstrap
git diff --check
```

Run the relevant real WordPress smoke when a moved callback depends on WordPress
runtime behavior. Block-theme or Site Editor slices must also run
`composer smoke:block-theme-host-proof`.

## Ownership Rule

Traits own cohesive method implementations only. `Core_Read_Package` and
`Core_Write_Package` remain the package composition roots and definition owners.
No trait may introduce workflow runtime, routing decisions, approval storage,
audit storage, queues, schedules, retries, leases, or final authorization.
