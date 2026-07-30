# Agent Development Guide

This repository is usually developed by one maintainer working with AI agents.
Keep the process light, but preserve reviewable changes, stable contracts, and
WordPress.org release safety.

The full solo AI development operating workflow is summarized in
`docs/solo-ai-development-workflow.md`.
Repository-wide audits, gate hardening, evidence classification, and
post-merge closeout follow `docs/system-audit-and-closeout-standard.md`.

Cross-project platform coordination starts from
`/Users/muze/gitee/npcink-workflow-toolbox/docs/platform/README.md`. This
repository remains the ability contract owner; do not expand platform,
governance, runtime, connector, product-surface, or release-coordination rules
here beyond Toolkit-owned ability contracts.

## Startup Checklist

Before changing files:

1. Run `git status --short --branch`.
2. Identify the current branch and compare it with `origin/master` when the work
   is intended for a pull request.
3. Do not include unrelated local edits in your commit or pull request.
4. Read `CONTRIBUTING.md` and the relevant docs before changing ability
   contracts, governance boundaries, or release tooling.
5. For AI-assisted work, write a compact change envelope before editing: target
   repositories, focused module, intended change, explicit non-goals, public
   contracts touched, expected files, files or areas that must not change,
   required gates, cross-repo matrix requirement, and rollback plan.

## Project Boundaries

Npcink Abilities Toolkit owns ability registration helpers, category helpers,
schema and annotation normalization, first-party ability contracts, reusable
static workflow definitions, permission callbacks, risk metadata, diagnostics
redaction, and bounded performance checks.

This package does not own model routing, prompt selection, workflow runtime
execution, final write authorization, approval storage, billing, quota, cloud
execution, or MCP gateway policy.

Product UI, market packaging, China-market site-owner workflows, commercial
onboarding, and end-user toolbox experiences belong in consuming products, not
in this package.

This repository is the canonical owner of reusable, static workflow
definitions for the Npcink stack. Do not turn that definition catalog into a
host/runtime workflow registry, a second ability registry, or a second
WordPress control plane. Write-like behavior must stay dry-run or host-governed
where the existing contracts require it.

Workflow definition helpers in this repository are static, read-only recipe
metadata for host-side composition. They must not gain workflow state,
scheduling, retries, queues, leases, approval stores, audit stores, prompt
registries, model routing, or final write authority. `composer check:boundary`
must continue to guard those forbidden fields.
See `docs/workflow-definition-contract.md` for the complete forbidden field
list guarded by `composer check:boundary`.

## Branch And PR Discipline

- Use topic branches, normally `codex/short-description`.
- Use pull requests for `master`.
- Draft PRs are preferred while AI-generated changes are still being checked.
- Do not enable or require extra human reviewers by default; this is mainly a
  solo-maintainer repository.
- Required CI and required conversation resolution are the main merge gates.
- Before staging, inspect `git status --short` and `git diff --stat`. Do not
  use `git add -A` in a mixed worktree.
- Do not run `git reset --hard`, `git checkout -- .`, or equivalent destructive
  cleanup unless the user explicitly asks for that exact operation.
- When a file contains unrelated hunks, stage only the intended hunk with
  `git add -p` or `git apply --cached`.
- Before committing, verify `git diff --cached --stat` and
  `git diff --cached --name-only`; after committing, verify
  `git show --name-status --stat HEAD`.
- If unexpected files entered a commit, use `git reset --mixed HEAD~1` and
  recommit the correct scope. This preserves the working tree while fixing the
  commit boundary.
- A local branch that is ahead of its upstream is not published. At closeout,
  either push/open or update the PR, or explicitly record why the commits remain
  local-only.
- Publish a completed clean topic branch with
  `composer pr:publish -- --title "<title>" --body-file <path>`. Start the body
  from `.github/pull_request_template.md`; do not replace it with ad hoc
  `gh pr create --body` text that omits `Scope`, `Boundary`, `Verification`, or
  `Risk`.
- The publisher checks the current `origin/master` baseline, creates the PR,
  and requests protected squash auto-merge. It never bypasses required checks
  and never deletes local or remote branches, because this repository commonly
  uses multiple worktrees.
- The cross-repository contract is
  `/Users/muze/gitee/npcink-workflow-toolbox/docs/platform/pr-publishing-standard-v1.md`.
- For multi-repo closeout, run the central quality matrix from
  `/Users/muze/gitee/npcink-workflow-toolbox`: `composer quality:matrix` for
  status and `composer quality:matrix:run` for the gate-running matrix. Do not
  copy that cross-repo script into this repository.

## Post-Merge Cleanup

After useful code is merged to `master`:

1. Fetch and prune remotes, then fast-forward the main worktree to
   `origin/master`.
2. Confirm the main worktree is on `master` with `git status --short --branch`.
3. Inspect `git worktree list --porcelain`; remove only clean auxiliary
   worktrees whose branches are fully merged.
4. Delete local merged topic branches with `git branch -d`, not force delete.
5. Check remote branches with `git ls-remote --heads origin`; delete stale
   Codex branches only after their useful commits are merged or intentionally
   preserved in a new pull request.
6. Leave Dependabot or other maintenance branches alone unless their pull
   requests are reviewed, current with `master`, and required CI has passed.
7. If unrelated local edits remain, call them out explicitly and do not revert,
   stage, or include them in cleanup commits.

The target steady state after cleanup is one local worktree on `master`, no
stale Codex branches, no open obsolete pull requests, and `origin/master` as
the only non-maintenance remote branch.

## When To Open Issues

Do not create an issue for every small fix. Open issues for durable work that
needs tracking across more than one short session:

- WordPress.org review feedback;
- release tasks;
- ability contract changes;
- security or governance-boundary risks;
- multi-day or cross-repository work.

Small local fixes can go straight to a branch and pull request.

## Verification

Use the smallest relevant gate while iterating, then run the broader gate before
handoff or merge.

Default source gate:

```bash
composer test:all
composer analyse:phpstan
git diff --check
```

Release-facing gate:

```bash
composer check:boundary
composer check:wporg
WP_PATH=/path/to/wordpress composer release:verify
```

Before preparing WordPress.org SVN from source, run those same explicit
boundary and WordPress.org static guards:

```bash
composer check:boundary
composer check:wporg
```

For this device, WP-CLI is installed globally:

```bash
/opt/homebrew/bin/wp
```

WP-CLI is only a command-line tool. It still needs a real WordPress site target
through `WP_PATH` or `--path`. Local.app WordPress sites are useful and should be
kept for smoke tests, Plugin Check, and release verification.

For Local.app database socket issues, pass the site-specific socket through
`WP_CLI_MYSQL_SOCKET`. See `docs/wordpress-org-release-runbook.md` for the
current release verification example.

## Release Rules

- Do not retag historical releases.
- Release work should update the plugin header version,
  `NPCINK_ABILITIES_TOOLKIT_VERSION`, `readme.txt`, `CHANGELOG.md`, and a
  release verification note.
- Use `composer check:boundary`, `composer check:wporg`, and
  `composer release:verify` before tagging or WordPress.org publishing.
- Verify boundary-language drift across `AGENTS.md` and
  `docs/workflow-definition-contract.md` before tagging.
- Use `VERSION=X.Y.Z composer release:prepare-wporg` to prepare the local SVN
  working copy.
- WordPress.org SVN credentials must be typed only into the user's terminal.

## Prompt Replacement

The maintainer should not need to paste a long project prompt every time. Treat
this file as the reusable prompt for future AI sessions. If a tool does not read
`AGENTS.md` automatically, the first instruction should be only: "Read
`AGENTS.md` first."
