# Solo AI Development Workflow

Status: active operating standard.
Last updated: 2026-07-30.

This document summarizes the current solo-maintainer plus AI-agent workflow for
`npcink-abilities-toolkit`. It turns the recurring chat decisions into a
single reusable operating guide.

For the repository-wide audit sequence, evidence ladder, five-axis review, and
closeout record template, also read
[System Audit And Closeout Standard](system-audit-and-closeout-standard.md).

## Operating Model

This repository is usually maintained by one person working with AI agents.
Keep the process lightweight, but preserve:

- reviewable pull requests;
- stable ability contracts;
- host-governed write boundaries;
- reproducible local and GitHub checks;
- WordPress.org release safety.

`master` is the protected source of truth. Normal changes should move through a
topic branch, pull request, GitHub Actions, and merge back to `master`.

## Starting A Session

Before an AI agent changes files:

```bash
git status --short --branch
git fetch --prune origin
```

Then confirm:

- the current branch;
- whether the work should start from `origin/master`;
- whether unrelated local edits already exist;
- which files and docs are in scope.
- the change envelope: target repositories, focused module, intended change,
  explicit non-goals, public contracts touched, expected files, files or areas
  that must not change, required gates, cross-repo matrix requirement, and
  rollback plan.

AI agents should read `AGENTS.md` first. If a tool does not automatically read
repository instructions, use this short prompt:

```text
Read AGENTS.md first.
```

## Normal Development Flow

Use a short topic branch:

```bash
git switch -c codex/short-description
```

Keep commits focused. Do not stage unrelated local edits. If unrelated changes
exist, report them separately instead of folding them into the task.

Before opening a pull request for code, contracts, or release tooling, run:

```bash
composer test:all
composer analyse:phpstan
git diff --check
```

For documentation-only changes, `git diff --check` plus the relevant project
gate is usually enough. Use `composer test:all` when the document updates
operating rules that tests or release checks rely on.

## Commit Scope Gate

Before staging, inspect:

```bash
git status --short --branch
git diff --stat
```

If unrelated local edits already exist, report them separately and leave them
unstaged. Do not use `git add -A` in a mixed worktree. If one file contains both
current-task and unrelated hunks, stage only the intended hunk with `git add -p`
or `git apply --cached`.

Before committing, verify:

```bash
git diff --cached --stat
git diff --cached --name-only
```

After committing, verify:

```bash
git show --name-status --stat HEAD
```

If unexpected files or hunks entered the commit, immediately run
`git reset --mixed HEAD~1` and recommit the correct scope. This keeps the
working tree changes intact while repairing the commit boundary.

Do not run `git reset --hard`, `git checkout -- .`, or equivalent destructive
cleanup unless the user explicitly asks for that exact operation.

## Cross-Repo Quality Matrix

For Toolkit-only work, keep using this repository's local gates. For multi-repo
work or milestone closeout, run the central matrix from
`npcink-workflow-toolbox`:

```bash
cd /Users/muze/gitee/npcink-workflow-toolbox
composer quality:matrix
composer quality:matrix:run
```

`composer quality:matrix` is the status-only check. `composer quality:matrix:run`
runs the configured gates across the related repositories. For release closeout
that must prove no repository has hidden local edits, run:

```bash
php scripts/cross-repo-quality-matrix.php --run-gates --fail-on-dirty
```

Do not copy the cross-repo matrix script into Toolkit. The central script remains
owned by `npcink-workflow-toolbox` so gate definitions stay in one place.

## Publication Status Gate

Local commits are not published work. Before calling a stage closed, run:

```bash
git status --short --branch
```

If the branch is ahead of its upstream, choose one of these outcomes:

- push the branch and open or update the PR;
- intentionally keep the commits local and record why;
- split or move the commits to a dedicated branch before publishing.

Do not describe a milestone as fully closed while omitting the branch
ahead/behind state or hiding remaining modified/untracked files.

## Pull Requests And GitHub Actions

Push the branch and open a pull request:

```bash
git push -u origin codex/short-description
gh pr create --base master --head codex/short-description
```

Required GitHub Actions checks:

- `php (8.0)`;
- `php (8.3)`;
- `PR body contract`;
- `wordpress-smoke (minimum)`;
- `wordpress-smoke (current)`.

Draft PRs are appropriate while an AI-generated change is still being checked.
Ready PRs can be merged after:

- useful scope is confirmed;
- CI passes;
- conversations are resolved;
- the branch is current with `master`.

If GitHub reports that the branch is not current with `master`, update the PR
branch and wait for checks again:

```bash
gh pr update-branch PR_NUMBER
gh pr checks PR_NUMBER --watch --interval 10
```

## Post-Merge Cleanup

After useful code is merged:

```bash
git fetch --prune origin
git switch master
git pull --ff-only origin master
git worktree list --porcelain
git branch --merged master
git ls-remote --heads origin
```

Clean up only what is safe:

- remove auxiliary worktrees only when they are clean;
- delete local topic branches with `git branch -d`;
- delete stale remote `codex/...` branches only after useful commits are merged
  or preserved in a new pull request;
- leave Dependabot or maintenance branches alone unless their PRs are reviewed,
  current with `master`, and CI has passed;
- do not revert, stage, or hide unrelated local edits.

The preferred steady state:

- one local worktree on `master`;
- no stale local Codex branches;
- no obsolete remote Codex branches;
- no open superseded pull requests;
- `master` aligned with `origin/master`.

## WP-CLI And Local WordPress

On this device, WP-CLI is installed globally:

```bash
/opt/homebrew/bin/wp
```

Other local WordPress projects can use the same command, but WP-CLI is only a
command-line tool. It still needs a real WordPress site target through `--path`
or `WP_PATH`.

Local.app WordPress sites are not redundant. Keep them for:

- real-site smoke tests;
- Plugin Check;
- WordPress.org release verification;
- site-specific WP-CLI commands.

For Local.app database socket issues, pass the site socket through
`WP_CLI_MYSQL_SOCKET`. The current release runbook contains the exact example:

```text
docs/wordpress-org-release-runbook.md
```

## Release And WordPress.org Publishing

Release-facing work should update:

- plugin header version;
- `NPCINK_ABILITIES_TOOLKIT_VERSION`;
- `readme.txt`;
- `CHANGELOG.md`;
- a matching `docs/release-*-verification.md` note.

Before WordPress.org publishing:

```bash
WP_PATH=/path/to/wordpress composer release:verify
VERSION=X.Y.Z composer release:prepare-wporg
```

WordPress.org SVN credentials must be typed in the user's terminal, not into
chat. Do not retag historical releases. Tag from the verified `master` commit.

## GitHub Features In Use

The current repository uses:

- protected `master`;
- required GitHub Actions checks;
- pull request template;
- Dependabot maintenance PRs;
- issue templates for release tasks and WordPress.org review feedback;
- Copilot instructions that point agents to `AGENTS.md`.

Useful future GitHub features should stay pragmatic. Prefer features that
improve reviewability, release safety, or repeated AI handoff quality. Avoid
process that assumes a large team unless the maintenance model changes.

## Prompt For Other AI Sessions

Use this minimal instruction when starting another AI development session:

```text
Read AGENTS.md first. Then inspect git status and follow docs/solo-ai-development-workflow.md.
```

If the task uses WP-CLI, also provide:

```text
WP-CLI is available at /opt/homebrew/bin/wp, but you must pass the target site
with WP_PATH or --path.
```

## When To Stop

A session is complete when:

- intended code or docs are merged or intentionally left in a PR;
- local checks and GitHub Actions are accounted for;
- worktrees and branches are cleaned up according to the post-merge rules;
- unrelated local edits, if any, are explicitly reported;
- the final state is clear enough for the next AI session to continue without
  rediscovering repository state.
