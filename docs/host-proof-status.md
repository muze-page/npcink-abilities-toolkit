# Host Proof Status

Status: active tracking note.
Date: 2026-07-11.

This note records the current host-proof ledger for the freeze/observe period.
It is intentionally a status document, not an ability backlog. A new Toolkit
ability remains justified only when a real host proof fails because a small,
reusable, WordPress-only contract is missing.

## Current Decision

Keep `npcink-abilities-toolkit` in freeze/observe mode.

- Do not open a new ability branch from candidate lists alone.
- Prefer host, Core, Adapter, or Toolbox fixes when the failure belongs to
  routing, approval, execution handoff, product UX, runtime, model selection,
  cloud execution, or prompt behavior.
- Create Toolkit work only for a proved WordPress-local contract gap or a
  proposal/dry-run schema gap that existing abilities cannot satisfy by
  composition.

## Ledger Entry Rule

Record only real host proofs here. Each new proof entry should identify:

- the host or consumer that ran the proof;
- the workflow recipe or ability chain under test;
- the exact abilities discovered through the WordPress Abilities API;
- whether the flow stopped at proposal, dry-run, or host-governed handoff;
- whether any WordPress mutation happened before approval;
- the validation commands or pull request evidence;
- the owner of any remaining gap.

Do not add candidate ability names, product ideas, prompt fixes, or runtime
tasks to this ledger. Those belong in the consuming host unless the proof fails
because an existing WordPress-local read/proposal/dry-run contract cannot
represent the needed reviewable artifact.

## Workflow Consumer Validation Target

Use existing workflow recipes as validation targets, not as a Toolkit runtime.
A valid host proof should:

1. Discover the recipe and abilities through the WordPress Abilities API or the
   Toolkit workflow discovery helpers.
2. Compose only the read or proposal handoff needed by the host.
3. Preserve the recipe reference and real ability ids in the host/Core proposal
   path when a proposal is created.
4. Verify that Toolkit did not directly mutate WordPress before approval and
   execution.
5. Reopen Toolkit work only when the failure is a small reusable contract gap
   in this package.

## Closed Proof State And Observation Queue

All three proof targets in the ledger are closed. Continue in this order:

1. Keep Toolkit in freeze/observe mode and do not add first-party abilities.
2. Keep the explicit real-host proof commands green and rerun the affected proof
   when a host, WordPress, or public ability contract changes.
3. If a proof fails, classify the gap before opening Toolkit work. Routing,
   prompt selection, model/runtime behavior, signed handoff, approval storage,
   audit truth, and product review UX belong outside Toolkit by default.
4. Reopen Toolkit work only for a small reusable contract gap owned by this
   package, and record the host, commands, ability chain, and no-direct
   WordPress mutation evidence in this ledger.
5. Improve packaged third-party onboarding only through release-visible
   discovery surfaces such as `readme.txt` and the admin page. Keep repository
   examples and long-form docs outside the release zip.

## Proof Ledger

| Proof target | Current state | Missing before closed-loop proof | Owner of next action |
| --- | --- | --- | --- |
| `npcink-abilities-toolkit/recipes/article-optimization` | Closed-loop host proof passed on 2026-06-17. Toolkit exposes the declarative workflow and replay fixture. Core smoke preserves the recipe ref through from-plan proposal intake and verifies no post excerpt mutation. Adapter pull requests #2 and #3 resolved signed-client preflight binding and dependency contract semantic checks. Adapter pull request #5 added and passed a real WordPress smoke that runs the Toolkit apply plan through Adapter `run-read-ability`, submits it through Adapter `/proposals/from-plan`, reads the Core proposal back through Adapter, and verifies the source recipe, no-direct-write posture, and unchanged post excerpt. | Keep observing; no Toolkit ability gap is open from this proof. | None. |
| `npcink-abilities-toolkit/recipes/article-media-handoff` | Closed-loop host proof passed on 2026-06-17. Toolkit exposes the declarative workflow/replay direction. Toolbox has adjacent article/media batch and media conversion review-set evidence that keeps Core proposal review and no-execute boundaries visible. Adapter pull request #6 added and passed a real WordPress smoke that discovers the workflow through Adapter, runs `build-media-seo-assets`, creates a governed Core `update-media-details` proposal from reviewed metadata, reads the proposal back through Adapter, and verifies the recipe ref, no-direct-write posture, and unchanged attachment metadata before approval/execution. | Keep observing; no Toolkit ability gap is open from this proof. | None. |
| Block theme / Gutenberg intent routing | Real-host proof passed on 2026-07-11 against WordPress `7.1-alpha-62692` with Twenty Twenty-Five 1.5 active. The proof discovered the existing routing, pattern-page, block-theme planning, template, and template-part abilities through WordPress Abilities REST; read, parsed, and rendered the real `single` template plus file-backed `header` template part; routed landing-page and bounded Site Editor intents; parsed and rendered proposed blocks through the real Core block engine; and completed 49 assertions. The pattern plan exposed governed `create-draft` plus `update-post-blocks` targets, while the Site Editor plan exposed `update-template-blocks`. Every action remained approval-required with `dry_run=true` and `commit=false`. The before/after page, template, template-part, related postmeta, theme, and reading-setting fingerprint remained identical. | Keep the explicit real-host proof command green. No new Toolkit ability gap was found, so freeze/observe remains the correct posture. | None. |

The deterministic Gutenberg composer pilot remains useful contract evidence,
while the explicit real-host proof now supplies the previously missing host
path and no-mutation evidence.

Run the proof with:

```bash
WP_CLI=/opt/homebrew/bin/wp \
WP_CLI_PHP=/opt/homebrew/bin/php \
WP_CLI_MYSQL_SOCKET="/Users/muze/Library/Application Support/Local/run/NPb24Zg9g/mysql/mysqld.sock" \
WP_PATH="/Users/muze/Local Sites/magick-ai/app/public" \
composer smoke:block-theme-host-proof
```

The generated evidence file is intentionally ignored under
`build/block-theme-host-proof.json`; the durable contract is the repeatable
proof command plus this ledger entry, not a committed snapshot of one local
site.

The Block theme / Gutenberg proof does not block basic third-party provider
onboarding. Provider plugins that register read-only abilities or
write-proposal abilities through the public helpers can proceed while that
host-side proof remains open.

## Adapter Pull Request Disposition

Adapter pull request #2, "Bind Adapter preflight handoffs to signed clients",
merged on 2026-06-17:

- https://github.com/muze-page/npcink-ai-client-adapter/pull/2

The pull request merged two commits:

- `045dc74 Tighten adapter execution handoff validation`
- `9032d76 Bind Core preflight to signed local clients`

Adapter pull request #3, "Validate dependency contract semantics", also merged
on 2026-06-17:

- https://github.com/muze-page/npcink-ai-client-adapter/pull/3

That pull request merged:

- `f6099b6 Validate dependency contract semantics`

Adapter pull request #5, "Add article optimization host proof smoke", merged on
2026-06-17:

- https://github.com/muze-page/npcink-ai-client-adapter/pull/5

That pull request merged:

- `1f2cb9c Add article optimization host proof smoke`

Adapter pull request #6, "Add article media handoff host proof smoke", merged on
2026-06-17:

- https://github.com/muze-page/npcink-ai-client-adapter/pull/6

That pull request merged:

- `01db3ce Add article media handoff host proof smoke`

Local validation before merge:

```bash
composer test:all
git diff --check master..codex/approve-and-execute
```

The dependency contract semantics follow-up was also locally validated with:

```bash
composer test:all
git diff --check
```

The article optimization host proof was locally validated with:

```bash
composer test:all
WP_CLI=/tmp/wp-cli.phar \
WP_CLI_PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.5.3+1/bin/darwin-arm64/bin/php" \
WP_CLI_MYSQL_SOCKET="$HOME/Library/Application Support/Local/run/NPb24Zg9g/mysql/mysqld.sock" \
composer smoke:wp
git diff --check
```

The article media handoff host proof was locally validated with:

```bash
php -l tests/smoke-wp.php
git diff --check
composer test:all
WP_CLI=/tmp/wp-cli.phar \
WP_CLI_PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.5.3+1/bin/darwin-arm64/bin/php" \
WP_CLI_MYSQL_SOCKET="$HOME/Library/Application Support/Local/run/NPb24Zg9g/mysql/mysqld.sock" \
composer smoke:wp
```

Disposition: the Adapter branches are resolved and
`npcink-abilities-toolkit/recipes/article-optimization` plus
`npcink-abilities-toolkit/recipes/article-media-handoff` are closed-loop proven from current
main branches. No Toolkit follow-up ability is justified by these proofs.

## Failure Classification

Use this classification before creating Toolkit work:

| Failure | Default owner | Toolkit work? |
| --- | --- | --- |
| Host cannot decide when to call a recipe | Host or Adapter | No |
| Prompt, model, or runtime selection fails | Host, runtime, or Cloud | No |
| Approval, audit, or preflight is missing | Core or governance host | No |
| Final write execution handoff is not signed or bounded | Adapter and Core | No, unless the ability schema is insufficient |
| Product UI cannot display the review set | Toolbox or product host | No |
| WordPress-local context is missing from an existing read/proposal contract | Toolkit | Maybe |
| A dry-run/proposal schema cannot express a reviewable plan | Toolkit | Maybe |

## Release Posture

The `0.5.3` release is complete. This status note does not open another release.
A maintenance release is reasonable only if new proof work stays limited to
documentation, replay fixtures, contract snapshots, CI checks, or
existing-ability hardening.

Do not treat the current state as `0.6.0` material unless a real host proof
fails and identifies one to three small, reusable, WordPress-only gaps that
cannot be fixed in host routing, Core governance, Adapter handoff, Toolbox UX,
Cloud runtime, or prompt design.
