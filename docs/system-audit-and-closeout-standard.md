# System Audit And Closeout Standard

Status: active operating standard.
Last updated: 2026-07-30.

This document turns the 2026-07-30 system audit and closeout into a reusable
development standard for `npcink-abilities-toolkit`. It records both the
historical evidence from that audit and the durable reasoning that future
maintainers and AI agents should apply.

Use this standard when:

- reviewing overall repository health;
- hardening source, CI, runtime, or release gates;
- deciding whether a large module should be split;
- publishing a maintenance pull request;
- cleaning branches or worktrees after merge;
- deciding whether a stage is actually complete.

This document does not replace the focused contract, testing, release, or
workflow-definition documents. It explains how to combine their evidence
without confusing one evidence level for another.

## Source Of Truth And Document Lifetimes

Keep three kinds of information separate:

1. **Active rules** describe what every future change should do. They belong in
   `AGENTS.md`, `CONTRIBUTING.md`, this standard, or another active contract.
2. **Historical evidence** records what passed for one exact revision. It must
   include a date, commit or pull request, commands, and known limitations.
3. **Live state** includes branches, worktrees, Local.app mounts, CI runs,
   protected-check settings, tags, and remote repositories. Recheck it before
   acting; do not copy an old snapshot forward as current truth.

When these disagree, do not silently pick the most convenient statement.
Verify the live state, update the active rule if policy changed, and preserve
the historical record as history.

## 2026-07-30 Audit Closeout Snapshot

This section is evidence for one completed revision, not a promise about all
future revisions.

- Pull request:
  [#104 Harden analysis, release, and runtime gates](https://github.com/npcink/npcink-abilities-toolkit/pull/104)
- Merged revision: `625a107cadabe2712c95b01eeb415e562360b94a`
- Release decision: no new version or tag; published `0.5.3` remained
  immutable.
- Public contract decision: no ability id, schema, annotation, callback,
  permission, workflow recipe, dry-run default, approval ownership, or final
  authorization change.

The closeout addressed these findings:

| Finding | Resolution | Durable rule |
| --- | --- | --- |
| PHPStan could exit without analyzing the intended source | Removed the bootstrap side effect, upgraded PHPStan, fixed real findings, and made the command fail closed | Validate that a gate actually runs and analyzes files; a zero exit code alone is not evidence |
| Local PHPStan passed while clean CI failed | Marked the optional `dist` exclusion explicitly | Reproduce gates in a clean checkout and treat local generated paths as optional |
| Release artifacts could be built from mutable or ambiguous source | Required a clean, non-shallow checkout and exact existing-tag revision; emitted source and ZIP hashes | A release artifact must be traceable to one immutable source revision |
| Runtime evidence covered an incomplete compatibility range | Added minimum WordPress/PHP and current WordPress/PHP smoke jobs | Test the declared floor and the current endpoint; source tests cannot replace runtime evidence |
| Unit capability stubs could accidentally allow unknown capabilities | Changed the stub to fail closed and added subscriber REST denial evidence | Permission fixtures must deny unknown or misspelled capabilities |
| Documentation described a completed proof as still pending | Reconciled the proof ledger and operating standard | Tests should protect semantic state, not freeze stale prose |
| A large write package mixed media-reference discovery with orchestration | Moved only evidence-backed private discovery helpers into a trait | Split by cohesive responsibility and measured change pressure, never by line count alone |
| WordPress smoke could leave activation or MU-plugin state behind | Restored the original activation state and used a unique temporary MU-plugin file | A smoke test must restore every state it temporarily owns |
| Plugin uninstall left persistent options | Added single-site and multisite cleanup with behavioral tests | Persistent plugin-owned state needs an explicit uninstall lifecycle |
| Plugin Check reported atomic file and SQL paths as errors | Verified the data flow and added narrow, reasoned exceptions only where WordPress abstractions cannot preserve exclusive-create or row-lock semantics | Never suppress a category broadly; justify the exact operation and retain security semantics |
| Topic-branch pushes duplicated CI work | Limited push CI to `master` while pull requests retain the full matrix | Run each required candidate gate once per revision |

Recorded verification for that revision:

| Evidence layer | Result |
| --- | --- |
| Repository source gate | `composer test:all`; 6,634 assertions plus repository guards |
| Static analysis | PHPStan 2.2.7; no errors |
| Diff integrity | `git diff --check`; pass |
| Boundary and WordPress.org static guards | Pass |
| Minimum runtime | WordPress 6.9.4 / PHP 8.0; 443 smoke assertions |
| Current runtime | WordPress 7.0 / PHP 8.5; 441 smoke assertions |
| Mounted Local runtime | WordPress 7.1 beta; default 443 and light-profile 58 smoke assertions |
| Packaged Plugin Check | No errors; documented non-blocking warnings remained |
| GitHub protected checks | PHP 8.0, PHP 8.3, PR body contract, minimum smoke, and current smoke passed |
| Publication | Protected squash merge completed; `master` synchronized |

Do not reuse these counts as evidence for a later commit. Re-run the relevant
gate and record the new result.

## Decisions And Rejected Alternatives

The audit deliberately rejected several tempting shortcuts:

- **Add a PHPStan baseline or broad ignores:** rejected because the gate had not
  been executing reliably and the newly visible findings were bounded enough
  to fix.
- **Retag `0.5.3` or rebuild it from newer source:** rejected because one
  version must identify one immutable source and artifact history.
- **Refactor the complete media lifecycle:** rejected because only
  content-reference discovery had repeated-change and isolation evidence.
- **Treat parseable JSON, HTTP 200, or administrator success as acceptance:**
  rejected because semantic contracts and lower-privilege denial paths are
  separate requirements.
- **Use WP_Filesystem for atomic exclusive creation:** rejected because it
  cannot preserve the `O_EXCL`-style ownership guarantee required by the
  rollback transaction.
- **Delete task-named folders directly:** rejected because a worktree can be
  dirty, locked, mounted by Local WordPress, or contain unpublished commits.
- **Call the stage complete after local gates:** rejected because the final PR
  head, required GitHub checks, merge revision, and post-merge cleanup are
  separate evidence.

## Principles

### 1. Facts Before Recommendations

Start with repository and runtime facts:

```bash
git status --short --branch
git fetch --prune origin
git worktree list --porcelain
```

Read `AGENTS.md`, `CONTRIBUTING.md`, the focused contract, and the applicable
runbook before recommending structural, release, or ownership changes.

### 2. Validate The Validator

Every gate has two questions:

1. Did the command exit successfully?
2. Did it execute the intended analysis against the intended source?

Check tool versions, loaded configuration, analyzed paths, assertion counts,
and expected failure behavior. Add a regression that proves a broken or empty
gate fails when practical.

### 3. Preserve Ownership Boundaries

Toolkit owns reusable ability contracts and static workflow definitions. It
does not own model routing, prompts, runtime execution, approval or audit
storage, billing, quota, Cloud execution, product UI, or final authorization.

An audit must not use cleanup, refactoring, or documentation as a pretext to
move host-owned behavior into Toolkit.

### 4. Fail Closed At Trust Boundaries

Unknown capabilities, unexpected schemas, ambiguous release source, unlisted
remote hosts, stale reviewed expectations, and unverifiable file ownership
must fail closed. Test the denial path, not only the administrator success path.

### 5. Preserve User And Host State

Do not reset, stash, overwrite, broadly stage, or delete work that is not owned
by the current task. Tests must restore activation, users, content fixtures,
temporary files, MU plugins, containers, and other state they create.

### 6. Separate Evidence Levels

A local test, a clean CI run, a merged commit, a live HTTP response, a packaged
Plugin Check, a Local WordPress smoke, and a production acceptance are distinct
claims. Report exactly which one passed.

## Audit Sequence

Run a system audit in this order. Later steps depend on the earlier facts.

### Step 1: Establish The Change Envelope

Record:

- target repository and focused module;
- intended change and explicit non-goals;
- public contracts touched;
- expected files and protected areas;
- required gates;
- whether a cross-repository matrix is needed;
- rollback plan.

If the checkout is dirty, preserve it. Use a clean topic branch or isolated
worktree when the intended files overlap existing changes.

### Step 2: Inventory Source And Repository State

Inspect:

- branch and upstream status;
- open pull requests and required checks;
- worktrees, locks, and mounted Local WordPress plugin targets;
- dependency and tool versions;
- largest or repeatedly changed modules;
- release tags and packaging scripts;
- stale active documentation.

Names and timestamps are only leads. A folder named after an old task may still
be mounted, locked, dirty, or contain unpublished commits.

### Step 3: Prove Gate Integrity

Check the source gate, static analysis, boundary guard, security guard,
performance checks, and package checks. Look for:

- bootstrap files that exit early;
- ignored paths that exist only locally;
- baselines or broad suppressions hiding new findings;
- commands that accept empty output;
- duplicate CI triggers;
- checks that run but are not required by branch protection.

### Step 4: Review Contracts And Permissions

Verify stable ids, schemas, risk metadata, annotations, dry-run behavior,
permission callbacks, REST exposure, and workflow-definition boundaries.

Use administrator success cases and lower-privilege denial cases. A permissive
unit stub is a test defect even if production code is correct.

### Step 5: Exercise Runtime Endpoints

Test both:

- the declared minimum WordPress and PHP versions;
- the current supported WordPress and PHP versions.

Use a real mounted Local WordPress site when release-facing behavior,
Plugin Check, or site-specific integration needs verification. Resolve the
actual plugin symlink before claiming which source was tested.

### Step 6: Audit Release Reproducibility

A release candidate must prove:

- clean source;
- complete Git history and tags;
- exact tag-to-commit binding when the tag already exists;
- package exclusions;
- boundary and WordPress.org checks;
- runtime smoke;
- source revision and artifact checksum.

Never retag a historical release to repair an old workflow or produce a
different archive with the same version.

### Step 7: Review Structure, Documentation, And Lifecycle

Split a module only when repeated changes, security isolation, test isolation,
or ownership confusion provides evidence. Move one cohesive responsibility and
keep public contracts unchanged.

Reconcile active documentation with the proof ledger. Add uninstall cleanup for
persistent options and restore all temporary smoke state.

### Step 8: Perform A Five-Axis Review

Before publication, review:

1. **Correctness:** expected and failure paths, bounds, types, and state
   restoration.
2. **Security and boundaries:** permissions, redaction, remote input, file
   ownership, SQL preparation, dry-run, and authorization ownership.
3. **Maintainability:** cohesive ownership, minimal suppressions, readable
   contracts, and evidence-backed refactors.
4. **Operations and release:** clean-checkout behavior, runtime endpoints,
   packaging, checksums, CI requirements, rollback, and cleanup.
5. **Documentation:** current active rules, dated historical evidence, valid
   links, and no release or production claim beyond the evidence.

### Step 9: Publish And Observe The Actual Candidate

Use the repository publisher:

```bash
composer pr:publish -- \
  --title "describe the focused change" \
  --body-file /absolute/path/to/completed-pr-body.md
```

Wait for every required check on the final head revision. If a push changes the
head, previous green checks no longer accept the candidate. Diagnose failures
from CI logs, fix them narrowly, push, and wait again.

### Step 10: Close The Loop

After merge:

```bash
git fetch --prune origin
git switch master
git pull --ff-only origin master
git worktree list --porcelain
git ls-remote --heads origin
```

Remove only clean, merged auxiliary worktrees with `git worktree remove`.
Resolve Local WordPress symlinks before removal. Use `git worktree prune` only
for confirmed stale registrations. Delete merged branches with `git branch -d`;
do not force-delete a branch merely because a squash merge makes ancestry less
obvious.

## Evidence Matrix By Change Type

| Change type | Minimum evidence |
| --- | --- |
| Documentation wording only | Link/path validation, focused semantic checks, `git diff --check` |
| Active operating or boundary documentation | Documentation checks, `composer check:boundary`, `composer test:all`, `git diff --check` |
| PHP implementation or contract | `composer test:all`, PHPStan, boundary when applicable, `git diff --check` |
| Permission, REST, or write behavior | Source gates plus success and denial runtime evidence |
| Bootstrap or compatibility baseline | Source gates plus minimum and current WordPress runtime smoke |
| Release tooling or package surface | Boundary, WordPress.org guard, `release:verify`, packaged Plugin Check, source/artifact hashes |
| Cross-repository contract | Local gates plus the central cross-repository quality matrix |
| Production-facing behavior | All applicable source and candidate gates plus separate deployment and live acceptance evidence |

Run a larger gate when uncertainty or risk is higher. A documentation-only
label does not justify a smaller gate when the document controls release,
boundary, or operational behavior.

## Required GitHub Checks

At the 2026-07-30 closeout, strict `master` protection required:

- `php (8.0)`;
- `php (8.3)`;
- `PR body contract`;
- `wordpress-smoke (minimum)`;
- `wordpress-smoke (current)`.

This list is live repository configuration, not a permanent constant. Verify it
through GitHub before changing CI names or declaring the protection current.

## Worktree Cleanup Decision

Classify every worktree before removal:

| State | Action |
| --- | --- |
| Dirty | Preserve; identify the owner and unpublished changes |
| Mounted by Local WordPress | Preserve until the mount is intentionally moved and revalidated |
| Locked or used by another session | Preserve |
| Clean with an open or unknown PR | Preserve until publication state is resolved |
| Clean, merged, and unmounted | Remove with `git worktree remove` |
| Missing directory with stale registration | Prune only after confirming the exact stale registration |

Worktrees share the repository object database. Cleanup is primarily about
clarity and collision avoidance, not large disk savings.

## Closeout Record Template

Use this compact structure in a pull request, closeout document, or final
handoff:

```text
Scope:
- repository, branch, focused responsibility
- explicit non-goals and contracts unchanged

Changes:
- concrete findings resolved
- decisions and rejected alternatives

Verification:
- source gates and assertion/tool versions
- runtime versions and smoke counts
- package/release evidence
- CI result for the final head revision

Publication:
- PR URL, merge revision, release/deploy decision

Cleanup:
- master/upstream state
- worktrees and branches removed or intentionally preserved
- Local mounts and unrelated edits preserved

Residual:
- non-blocking warnings
- deferred issues and their evidence trigger
- what must be revalidated next time
```

## When The Work Is Complete

A stage is complete only when:

- the intended change is merged or intentionally left in a clearly identified
  pull request;
- required checks belong to the final candidate revision;
- runtime, package, release, and production claims are separated;
- active documentation matches current policy;
- historical evidence is dated and revision-bound;
- temporary state and clean merged worktrees are removed;
- dirty, mounted, locked, or unpublished work is explicitly preserved;
- the next maintainer can continue without reconstructing hidden state.

Reopen work only for new evidence: a failed current gate, a consumer contract
gap, a security or release risk, repeated ownership collisions, or an approved
feature. Do not keep polishing a closed stage merely because more restructuring
is possible.

## Related Documents

- [Solo AI Development Workflow](solo-ai-development-workflow.md)
- [Testing Strategy](testing-strategy.md)
- [Security And Governance Gates](security-and-governance-gates.md)
- [Workflow Definition Contract](workflow-definition-contract.md)
- [Structural Split Plan](structural-split-plan.md)
- [Local WP-CLI Smoke Test](local-wpcli-smoke.md)
- [WordPress.org Release Runbook](wordpress-org-release-runbook.md)
- [GitHub Publication And Continuous Gates](github-publication-and-continuous-gates.md)
