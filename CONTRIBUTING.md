# Contributing

Thanks for contributing to Npcink Abilities Toolkit. This project is a
foundation-layer WordPress Abilities API plugin, so changes should preserve
stable ability contracts, host-governed write boundaries, and bounded
performance behavior.

For the complete solo-maintainer plus AI-agent operating workflow, see
[docs/solo-ai-development-workflow.md](docs/solo-ai-development-workflow.md).
For repository-wide audits, evidence classification, release hardening, and
post-merge closeout, use
[docs/system-audit-and-closeout-standard.md](docs/system-audit-and-closeout-standard.md).

## Branches And Pull Requests

Use pull requests for changes to `master`. The `master` branch is protected,
requires GitHub Actions to pass before merge, and should not receive direct
pushes during normal development.

Use short topic branches:

```bash
git switch -c codex/short-description
```

Complete a copy of `.github/pull_request_template.md`, then publish the clean
branch and request protected squash auto-merge:

```bash
composer pr:publish -- \
  --title "fix: describe the focused change" \
  --body-file /absolute/path/to/completed-pr-body.md
```

The publisher requires `Scope`, `Boundary`, `Verification`, and `Risk`, checks
that the branch includes the latest `origin/master`, and does not delete
branches in multi-worktree repositories. Wait for the required GitHub checks to
pass before merging. If GitHub reports
that required status checks were bypassed, treat it as an operations exception:
confirm that the pushed commit's checks completed successfully, record why a
direct push was necessary, and return to the pull-request path for the next
change.

This repository is usually maintained by one person working with AI agents. Keep
pull requests lightweight and reviewable. Draft PRs are appropriate while an
agent-produced change is still being checked. Required CI and required
conversation resolution are the normal merge gates; do not add mandatory human
review requirements unless the maintenance model changes.

Before opening a pull request:

```bash
composer test:all
composer analyse:phpstan
```

When the change touches documentation boundaries, public APIs, workflow recipes,
ability contracts, projection, admin surfaces, release packaging, or governance
text, also run the explicit boundary guard before handoff:

```bash
composer check:boundary
```

The required GitHub checks are:

- `php (8.0)`
- `php (8.3)`
- `PR body contract`
- `wordpress-smoke (minimum)`
- `wordpress-smoke (current)`

## Post-Merge Cleanup

After a pull request is merged, keep the repository easy for the next AI session
to reason about:

```bash
git fetch --prune origin
git switch master
git pull --ff-only origin master
git worktree list --porcelain
git branch --merged master
git ls-remote --heads origin
```

Remove clean auxiliary worktrees and delete local topic branches only when they
are already merged. Delete stale remote `codex/...` branches only after their
useful commits have landed on `master` or have been moved into a new pull
request. Do not include unrelated local edits in cleanup commits; report them
separately.

The preferred end state is:

- one local worktree on `master`;
- no stale local Codex branches;
- no obsolete remote Codex branches;
- no open pull requests that have already been superseded;
- `master` aligned with `origin/master`.

## Agent-Assisted Development

AI agents should read [AGENTS.md](AGENTS.md) before making changes. That file is
the reusable project prompt and records the repository-specific rules for
branching, boundaries, verification, Local.app, WP-CLI, and release work.

At minimum, agents should:

- run `git status --short --branch` before editing;
- avoid staging unrelated local changes;
- preserve host-governed write and approval boundaries;
- use `composer test:all`, `composer analyse:phpstan`, and `git diff --check`
  before handoff when code or contracts change;
- use `composer check:boundary` when docs, public APIs, workflow recipes,
  projection, admin surfaces, release packaging, or governance text change;
- use `WP_PATH=/path/to/wordpress composer release:verify` for release-facing
  changes.

Do not open an issue for every small fix. Use GitHub issues for durable work
that needs tracking across sessions: WordPress.org review feedback, release
tasks, ability contract changes, security or governance-boundary risks, and
multi-day work.

## Local Development Gate

Run the default source gate before merging changes that affect registered
abilities, schemas, metadata, workflow recipes, projection, diagnostics, or
host-governed write behavior:

```bash
composer test:all
```

Use targeted gates while iterating:

```bash
composer test
composer audit:composer
composer check:contracts
composer check:core-convergence
composer check:consumer
composer check:workflow-consumer
composer check:mcp-exposure
composer check:provider-demo
composer check:catalog
composer check:wporg
composer perf:smoke
```

Run PHPStan when public PHP contracts, class boundaries, bootstrap assumptions,
or typed contracts change:

```bash
composer analyse:phpstan
```

## WordPress Smoke

When a real WordPress site is available, run:

```bash
WP_PATH=/path/to/wordpress composer smoke:wp
```

For the current Local development site and socket-aware command, see
[docs/local-wpcli-smoke.md](docs/local-wpcli-smoke.md).

## What Belongs Here

This package owns:

- ability registration helpers and category helpers;
- schema, annotation, and metadata normalization;
- first-party read, write, and destructive ability contracts;
- read-only workflow definition helpers that expose static recipe metadata for
  host-side composition;
- permission callbacks and risk metadata;
- dry-run defaults and host approval-context requirements;
- diagnostics redaction and bounded performance checks.

This package does not own:

- model routing or prompt selection;
- workflow runtime execution;
- workflow state, scheduling, retries, queues, leases, approval stores, audit
  stores, prompt registries, or final write authority;
- final write authorization;
- approval storage or audit truth;
- quota, billing, app keys, cloud execution, or MCP gateway policy.

Toolkit is the canonical owner of reusable static workflow definitions for the
Npcink stack. Do not turn that definition catalog into a host/runtime workflow
registry, a second ability registry, or a second WordPress control plane.

If a change would move host-owned runtime behavior into this package, reject the
move or split it into the host-owned repository. Design documents may record the
rejection, split, or handoff, but they must not approve runtime/control-plane
ownership inside this package.

## Adding Or Changing Abilities

Before adding a first-party ability, prove that existing abilities cannot cover
the workflow through composition.

When an ability contract changes, update the focused checks and fixtures that
protect the public surface:

- `composer check:contracts` for ids, schemas, risk metadata, dry-run controls,
  approval flags, REST metadata, MCP metadata, and agent usage guidance;
- `composer check:consumer` and `composer check:catalog` for Core governance
  handoff and high-value consumer payloads;
- `composer check:workflow-consumer` for workflow recipes and replay fixtures;
- `composer check:mcp-exposure` for static ability metadata and exposure-safety
  annotations only; it does not own MCP gateway policy, server routing,
  admission, or runtime exposure decisions;
- `composer perf:smoke` for performance-sensitive read chains, aggregators,
  diagnostics, or cache behavior.

## Release Work

Do not retag historical releases. For a patch release, update the plugin
version, `readme.txt`, `CHANGELOG.md`, and a release verification note, then
tag from the verified `master` commit.

Before a GitHub or WordPress.org release:

```bash
composer check:boundary
composer check:wporg
WP_PATH=/path/to/wordpress composer release:verify
```

Record the evidence in the matching `docs/release-*-verification.md` file.

Before publishing to WordPress.org, follow
[docs/wordpress-org-release-runbook.md](docs/wordpress-org-release-runbook.md).
