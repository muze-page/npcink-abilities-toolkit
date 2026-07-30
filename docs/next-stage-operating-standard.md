# Next Stage Operating Standard

Status: active
Date: 2026-07-30

This standard captures the next-stage operating rules for
`npcink-abilities-toolkit` after the 0.5 contract-readiness line. It turns the
current positioning into concrete gates for ability additions, workflow recipe
promotion, performance checks, and security boundaries.

## Positioning Summary

`npcink-abilities-toolkit` is the WordPress Abilities API ability content,
contract, and discovery layer. It owns stable ability ids, categories, schemas,
annotations, risk metadata, callbacks, dry-run previews, workflow recipe
definitions, and optional thin compatibility projection.

It does not own the end-user AI product, workflow runtime, model routing, MCP
transport, approval storage, audit truth, quota, billing, provider credentials,
cloud execution, or product-specific toolbox UX.

The next-stage priority is not broader ability volume. The priority is proving
that hosts can discover, compose, validate, and govern the existing ability
surface without this package drifting into runtime ownership.

## Principal Rule

Do not add a new first-party ability from a candidate list alone. Add one only
after a Core, Adapter, Toolbox, MCP host, or other consumer proof fails because a
small, reusable, WordPress-only ability contract is missing.

Before adding an ability:

1. Try the existing ability chain through WordPress Abilities API discovery and
   execution.
2. Describe the missing behavior as a workflow gap, not as a helper name.
3. Add or update the relevant task row in `docs/ability-acceptance-matrix.md`.
4. Prefer a read-only context or proposal ability.
5. Keep write and destructive completion host-governed.
6. Limit any justified batch to three to five abilities.
7. Add contract, replay, smoke, and boundary coverage before release.

## Current Freeze/Observe Work Queue

During the current freeze/observe period, do these in order:

1. Do not add new first-party abilities.
2. Strengthen `composer check:boundary` before expanding runtime-facing
   surfaces.
3. Record real host proofs in `docs/host-proof-status.md` instead of turning
   candidate names into backlog items.
4. Treat workflow recipes as consumer validation targets: hosts should discover
   abilities, compose the handoff, enter proposal/dry-run governance, and stop
   before direct WordPress mutation.
5. Keep documentation entry points aligned. Prefer updating this standard,
   `docs/npcink-ai-project-split-contract.md`,
   `docs/security-and-governance-gates.md`,
   `docs/workflow-definition-contract.md`,
   `docs/admin-surface-standard.md`, and `docs/host-proof-status.md` over
   adding new positioning documents.
6. Fix only contract bugs unless a failed proof identifies a Toolkit-owned gap.
   Allowed fixes are schema, risk, permission, dry-run/commit defaults,
   diagnostics redaction, bounded performance, replay fixture, or documented
   host-proof gaps.

Current stage update:

- The article optimization, article media handoff, and Block theme / Gutenberg
  intent-routing proofs are closed-loop proven. Keep observing; no Toolkit
  ability gap is open from those proofs.
- There is no active proof target. Rerun the affected proof when a host,
  WordPress, or public ability contract changes, and reopen Toolkit work only
  when the failure identifies a small reusable contract gap owned here.
- Keep third-party integration improvements on packaged discovery surfaces:
  `readme.txt`, the admin discovery page, and REST/contract endpoint values.
  Do not ship the repository `docs/`, `examples/`, or scripts in the release
  zip just to make provider onboarding easier.

## Implementation Order

### 1. Promote Workflow Proof Before Ability Expansion

Use host workflow proofs as the main discovery mechanism. The closed workflow
proof targets retained for regression evidence are:

- `npcink-abilities-toolkit/recipes/article-optimization`
- `npcink-abilities-toolkit/recipes/article-media-handoff`
- Block theme / Gutenberg intent routing

The current proof ledger is tracked in `docs/host-proof-status.md`.

Promotion means adding declarative workflow definitions, replay fixture coverage,
and smoke guidance. It does not mean adding runtime execution, scheduling,
queues, prompt selection, model selection, approval state, audit state, or final
write authority.

### 2. Keep Recipes Declarative

Workflow definitions may describe:

- natural task examples;
- preferred read-only entrypoint abilities;
- expanded read or proposal chains;
- required scopes and inputs;
- expected output sections;
- host handoff shape;
- fail-closed behavior;
- write/destructive abilities that must not be selected as the default task
  entrypoint.

Workflow definitions must not contain runtime or governance fields. If a host
needs workflow state, retry policy, schedule, model routing, prompt registry,
approval policy, audit log, quota, commit policy, or final write authority, the
host owns that data.

### 3. Treat Performance As A Gate

Performance work should reduce repeated read calls and keep scans bounded. The
default tactics are read-only aggregator abilities, bounded pagination, short
TTL transients for deterministic read-only reports, user-aware cache keys, and
versioned invalidation on content, term, and attachment changes.

Do not cache write abilities, destructive abilities, approval-sensitive previews,
secret-bearing data, model/provider state, quota data, audit data, or unbounded
scans.

Run `composer perf:smoke` before release work and record real-site
`composer smoke:wp` evidence when a Local WordPress site is available.

### 4. Treat Security As Contract Discipline

Security work in this repository is contract and boundary discipline:

- WordPress permission callbacks run before previews and commits.
- Write and destructive abilities default to dry-run.
- Commits require host approval context.
- Risk metadata, MCP annotations, Npcink metadata, and REST visibility remain
  consistent.
- Write-like input schemas reject undeclared fields unless a documented
  exception exists.
- Diagnostics redact secrets, database names, table names, table prefixes,
  filesystem paths, unredacted log contents, cron arguments, and Npcink AI
  internals.

This package should not implement a security firewall, site backup system,
provider credential store, quota system, audit database, CDN operation suite, or
MCP governance runtime.

### 5. Keep Release Evidence Current

Release verification notes must match current checks. If the ability count,
assertion count, smoke coverage, or consumer proof changes, update the relevant
release verification note in the same change set.

Current baseline after this standard:

- `composer check:contracts` audits the registered first-party ability surface.
- `composer test:all` is the required local release gate.
- `composer perf:smoke` is the isolated local performance gate.
- `composer smoke:wp` remains the real-site WordPress proof when a Local WP site
  is available.

## Stop Conditions

Stop and keep the package in freeze/observe mode when:

- a candidate belongs to model routing, prompt ownership, workflow runtime,
  billing, quota, audit, approval, cloud execution, MCP governance, or end-user
  toolbox UX;
- existing abilities can already satisfy the workflow through composition;
- the proposed addition is only a product convenience label or runtime alias;
- the host proof has not been run yet.

Resume ability development only when a failed consumer proof identifies a
specific reusable WordPress contract gap.
