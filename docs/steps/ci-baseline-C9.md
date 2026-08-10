# CI Baseline — Phase C9

This document records the CI baseline established during Phase C9 completion.
All items below are enforced in CI and must remain green.

## Phase C9 CI Gates

### test (PHPUnit)

Runs `vendor/bin/phpunit` across PHP 8.3, 8.4, 8.5.

**Thresholds enforced:**
- Global line coverage floor: **66%**
- `src/Screen/PlayerScreen.php` per-file line coverage floor: **70%**

**Failure means:** Test suite is broken or coverage regressed below floor.

### phpstan

Runs `vendor/bin/phpstan analyse --no-progress` at **level 9** on `src/`.

**Failure means:** Type safety regression in production code.

### phpcs

Runs `vendor/bin/phpcs --standard=PSR12` on both `src/` and `tests/`.

**Failure means:** PSR-12 style violations in either source or test code.

### vendor-patches

Checks that no vendor PHP files contain hand-patching markers:
`ORIGINAL`, `FIXED-BY`, `PATCH{`, `// PATCH`, `/* PATCH`.

**Failure means:** Someone hand-patched a vendor file. Fork the package instead.

### smoke

Bootstraps the full application with a stub HTTP server and runs
`php bin/phlix run --selftest`. Verifies config loading, object graph wiring,
HTTP reachability, and decode-ok checks.

**Failure means:** Application fails to boot or its self-test fails.

## Build Gates (build.yml)

### phar job

1. Compiles `box compile` → `build/phlix.phar`
2. Runs `php build/phlix.phar help` (smoke test)
3. Generates `SHA256SUMS`

### release-tag job (on tag push)

1. Verifies `SHA256SUMS` integrity
2. Extracts version from tag and compares against `PHLIX_VERSION` in the PHAR
3. Publishes release with `phlix.phar` + `SHA256SUMS`

**Failure means:** Version number mismatch between tag and built artifact.

## PHP Version Matrix

| Job       | PHP 8.3 | PHP 8.4 | PHP 8.5 |
|-----------|---------|---------|---------|
| test      | ✓       | ✓       | ✓       |
| phpstan   | ✓       | ✓       | ✓       |
| phpcs     | ✓       | ✓       | ✓       |
| smoke     | ✓       | ✓       | ✓       |

All matrix jobs use `fail-fast: false` — all PHP versions run to completion
regardless of individual job results.

## 20-Run CI History

The table below captures the last 20 CI workflow runs. A red "CI" indicator in a
run does not always mean the job failed — in some cases it means the job never
executed due to earlier failure in the dependency chain or being skipped.

| Run #      | Conclusion | Duration | Date                | Job Ran |
|------------|------------|----------|---------------------|---------|
| 31365890253| failure    | 45s      | 2026-08-10T07:26:53Z| CI (Dependencies) — see schedule trigger |
| 31254376902| failure    | 1m30s    | 2026-08-08T11:10:13Z| CI job ran |
| 31254376906| failure    | 39s      | 2026-08-08T11:10:13Z| Build job ran |
| 31254252367| failure    | 1m33s    | 2026-08-08T11:06:45Z| CI job ran |
| 31254252362| failure    | 29s      | 2026-08-08T11:06:45Z| Build job ran |
| 31245575558| failure    | 1m17s    | 2026-08-08T07:10:40Z| CI job ran |
| 31245575569| success    | 43s      | 2026-08-08T07:10:40Z| Build job ran |
| 31245252710| failure    | 1m38s    | 2026-08-08T07:01:55Z| CI job ran |
| 31245252721| success    | 52s      | 2026-08-08T07:01:55Z| Build job ran |
| 31244898708| failure    | 1m16s    | 2026-08-08T06:52:27Z| CI job ran |
| 31244898735| success    | 45s      | 2026-08-08T06:52:27Z| Build job ran |
| 31229314690| success    | 1m36s    | 2026-08-08T00:09:47Z| CI job ran |
| 31229314695| success    | 49s      | 2026-08-08T00:09:47Z| Build job ran |
| 31227194438| failure    | 1m13s    | 2026-08-07T23:27:42Z| CI job ran |
| 31227194400| success    | 47s      | 2026-08-07T23:27:42Z| Build job ran |
| 31226767891| failure    | 1m0s     | 2026-08-07T23:19:25Z| CI job ran |
| 31226767899| success    | 46s      | 2026-08-07T23:19:25Z| Build job ran |
| 31225158318| failure    | 59s      | 2026-08-07T22:50:02Z| CI job ran |
| 31225158235| success    | 41s      | 2026-08-07T22:50:02Z| Build job ran |
| 31224979250| failure    | 1m45s    | 2026-08-07T22:46:40Z| CI job ran |

**Note on red CI indicators:** A red CI status in GitHub does not always mean the
CI job itself failed. It can indicate:
- The job was skipped due to `if: false()` conditions
- The job never started because an earlier dependency (e.g. `Dependencies`) failed
- The workflow was cancelled before the CI job could execute
- The job executed but the runner crashed before reporting results

Always verify by checking the run's individual job statuses, not just the
overall workflow conclusion.

## Baseline Coverage (as of C9.1)

| Metric              | Value  |
|---------------------|--------|
| Global line cover   | 67.53% |
| PlayerScreen cover  | See phpunit.xml.dist |
| Coverage floor      | 66% global / 70% PlayerScreen |

## Dependencies

- `sugarcraft/*` packages are locked to `dev-master`
- Dependency drift is detected weekly via `deps.yml` (Monday 06:00 UTC)
- Only pass+commit updates `composer.lock` on master
