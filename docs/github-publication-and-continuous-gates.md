# GitHub Publication And Continuous Gates

Status: active operations note.
Last verified: 2026-07-30.

This note records the public GitHub handoff and the current continuous
performance and security gates for `npcink-abilities-toolkit`.

## Repository State

The canonical public source repository is:

```text
https://github.com/npcink/npcink-abilities-toolkit
```

GitHub repository state verified on 2026-07-30:

- owner/name: `npcink/npcink-abilities-toolkit`;
- visibility: public;
- default branch: `master`;
- published branch: `master`;
- published release tags: `0.2.0`, `0.4.0`, `0.5.0`, `0.5.1`, `0.5.2`,
  and `0.5.3`;
- maintenance marker: `pre-refactor-2026-07-14`.

## Master Branch Protection

`master` is the published branch and should be updated through pull requests,
not direct pushes.

Required checks:

- `php (8.0)`;
- `php (8.3)`;
- `PR body contract`;
- `wordpress-smoke (minimum)`;
- `wordpress-smoke (current)`.

The checks are strict, so pull requests should be current with `master` before
merge. Administrator bypass should stay disabled in normal operations. If a
break-glass direct push is ever used, verify the resulting commit's GitHub
Actions run, record the reason in the release or operations note, and restore
the pull-request path immediately.

The local checkout should use the canonical GitHub repository as `origin`:

```text
origin  git@github.com:npcink/npcink-abilities-toolkit.git
```

GitHub redirects the earlier `muze-page/npcink-abilities-toolkit` URL, but local
checkouts should be normalized to the canonical owner before publication. The
previous Gitee remote, `git@gitee.com:gitgreat/npcink-abilities-toolkit.git`,
should not be used for this repository.

## Continuous Gate Baseline

The default source gate is:

```bash
composer test:all
```

It now includes:

- Composer metadata validation;
- Composer dependency advisory audit from `composer audit --locked`;
- project boundary checks;
- ability contract readiness;
- consumer and workflow handoff checks;
- official WordPress AI stack compatibility checks;
- MCP exposure audit;
- provider demo smoke;
- ability catalog audit;
- WordPress.org review guard;
- release-source immutability regression;
- WordPress smoke lifecycle restoration regression;
- single-site and multisite uninstall cleanup regression;
- bounded performance smoke;
- lightweight regression tests;
- PHP syntax linting.

The PHP CI matrix matches the package runtime floor:

- PHP `8.0`;
- PHP `8.3`.

The WordPress runtime matrix covers:

- WordPress `6.9.4` with PHP `8.0`;
- WordPress `7.0` with PHP `8.5`.

PHPStan also analyzes against PHP `8.0`, matching `composer.json`'s
`php >=8.0` requirement.

## Verified Commands

The following local checks passed after adding the dependency audit gate and
aligning PHP version targets:

```bash
composer validate --no-check-publish
composer audit:composer
composer test:all
composer analyse:phpstan
```

The 2026-07-30 publication baseline is pull request
[#104](https://github.com/npcink/npcink-abilities-toolkit/pull/104), merged as
`625a107cadabe2712c95b01eeb415e562360b94a`. The source, runtime, package, and
closeout evidence is summarized in
[System Audit And Closeout Standard](system-audit-and-closeout-standard.md).

## Known Historical CI Signal

The pushed historical tag `0.5.0` has a failed GitHub Actions run because that
tag's workflow still runs PHP `7.2` while the package already requires
`php >=8.0`.

Do not move the published `0.5.0` tag only to make that historical CI green.
Use the current `master` baseline for the next patch release instead.

## Future Release Follow-Up

Before the next public patch release after `0.5.3`:

1. Run the release gate from a clean, verified source checkout:

```bash
WP_PATH=/path/to/wordpress composer release:verify
```

For Local.app sites that require a MySQL socket, include
`WP_CLI_MYSQL_SOCKET=/path/to/mysqld.sock`.

2. Record the smoke and Plugin Check result in the next release verification
   note.
3. Tag a new patch release from the verified `master` commit instead of
   retagging a historical release.
