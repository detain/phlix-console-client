# CI Baseline — Phase C9

This document records the CI baseline established during Phase C9 completion.
All items below are enforced in CI and must remain green.

> **Currency pass (2026-10-29, review console__writer M-CONSOLE-53).** Every
> command claim below was re-anchored to the workflow files as they exist at
> `759d7c5`, and each carries its `path:line` citation so the next drift is
> catchable by reading the citation against the file. Sections that are dated
> historical records ("20-Run CI History", "Baseline Coverage (as of C9.1)")
> are kept verbatim; where a value recorded there is no longer enforced, that
> is stated rather than quietly rewritten.

## Current CI Gates (`.github/workflows/ci.yml`)

### test (PHPUnit)

Runs `vendor/bin/phpunit` (`ci.yml:35`) across PHP 8.3, 8.4, 8.5 (matrix
`ci.yml:14`), on runners that also get `ffmpeg` and `libsixel-bin` installed
(`ci.yml:25-26` — `sixel2png` is required by sugar-video's `GraphicsRenderer`;
without it SIXEL output is empty and those tests skip gracefully).

The run is governed by `phpunit.xml.dist`, which sets `failOnWarning="true"`
and `failOnRisky="true"` (`phpunit.xml.dist:7-8`): a PHP warning or a risky
test is a red build.

**No coverage threshold is enforced today.** The C9 baseline carried a global
line-coverage floor of **66%** and a `src/Screen/PlayerScreen.php` per-file
floor of **70%** via a `<threshold>` block in `phpunit.xml.dist` (added at
`5e71b9d`, C9.1). PHPUnit 11 schema validation rejected that element and
`failOnWarning` turned the config warning into a hard failure, so the threshold
was removed by `09b4228` (2026-08-22) and the cobertura report together with it
(`0e3dbce`, same day — no workflow consumed the artifact). The test job still
sets up `coverage: pcov` (`ci.yml:23`), but no CI step fails on coverage
anymore. Any future floor must live in an executable gate, not in this doc.

**Failure means:** the test suite is broken, or a warning/risky test appeared.

### phpstan

Two steps, both across PHP 8.3, 8.4, 8.5:

1. `vendor/bin/phpstan analyse --no-progress` (`ci.yml:57`) — `phpstan.neon` at
   **level 9** on `src/` (`phpstan.neon:2,5`).
2. `vendor/bin/phpstan analyse -c phpstan-tests.neon --no-progress`
   (`ci.yml:60`) — `phpstan-tests.neon` at **level 2** on `tests/`
   (`phpstan-tests.neon:32`; `tests/` carries 1000+ level-9 errors, see the
   note in `phpstan.neon:6-7`). This leg postdates C9.

**Failure means:** type-safety regression in production code, or in test code
at the level 2 bar.

### phpcs

**Not** bare `--standard=PSR12` on both trees. The job first asserts phpcs is
actually installed (`ci.yml:81-87`), then runs two steps across PHP 8.3, 8.4,
8.5:

1. `./vendor/bin/phpcs --standard=phpcs.xml --colors src/` (`ci.yml:109`)
2. `./vendor/bin/phpcs --standard=phpcs-tests.xml --colors tests/`
   (`ci.yml:119`)

`phpcs.xml` is a PSR-12 ruleset for `src/` with **zero sniff exclusions**; the
only remaining violation class there is `Generic.Files.LineLength` warnings,
printed but non-blocking via `ignore_warnings_on_exit` (see the file's
`<description>` for the 2026-10-02 dead-gate-repair measurement).
`phpcs-tests.xml` is PSR-12 for `tests/` excluding exactly one sniff
(`PSR1.Classes.ClassDeclaration.MultipleClasses`, measured and justified in its
`<description>`).

The gate is phpcs's **nonzero exit code**, never a grep of report prose — and
neither step may gain `-n` or `|| true` (the known dead-gate pattern; see the
step comments at `ci.yml:89-108` and `ci.yml:111-118`).

**Failure means:** PSR-12 **errors** in either source or test code (warnings
print but do not block, by explicit config policy).

### i18n (postdates C9)

`php scripts/check-i18n-hardcoded.php && php scripts/check-i18n-catalogs.php`
(`ci.yml:133`), single job on PHP 8.3 (`ci.yml:129`). Guards the hardcoded-
string scan list and the 7-locale catalog parity.

**Failure means:** a new hardcoded English string escaped translation, or the
locale catalogs drifted apart.

### vendor-patches

Greps vendor PHP files for hand-patching markers (`ci.yml:149`). The current
pattern matches: `FIXED-BY`, `PATCH{`, `// PATCH`, `/* PATCH`, and the
ANSI-red `PATCH` (`\x1b[1;31mPATCH`). Bare `ORIGINAL` is deliberately **not**
matched — vendor packages legitimately contain constants/comments such as
`COMPOSER_ORIGINAL_INIS` or `ORIGINAL_NODE`; it was dropped from the pattern by
`09b4228` (see the in-step comment at `ci.yml:147-148`).

**Failure means:** Someone hand-patched a vendor file. Fork the package instead.

### smoke

Bootstraps the full application with a stub HTTP server and runs
`php bin/phlix run --selftest`. The step (`ci.yml:180-191`) starts
`php -S 127.0.0.1:8099 tests/stub/router.php` in the same shell step
(background processes die with the step), waits on the port, then runs
`PHLIX_SERVER_URL=http://127.0.0.1:8099 php bin/phlix run --selftest`
(`ci.yml:191`). The job `needs: test` (`ci.yml:160`) and runs across PHP
8.3, 8.4, 8.5. The selftest (`runSelftest()` in `bin/phlix`) verifies config
loading, object graph wiring (including that `PlayerScreen`'s
`SyncPlayService` is non-null), HTTP reachability (one real `GET
/api/v1/libraries`), and decode-ok on the response body.

**Failure means:** Application fails to boot or its self-test fails.

### composer-audit — "Security Audit" (postdates C9, S246)

`php scripts/security-audit-check.php` (`ci.yml:230`) audits the **whole**
`composer.lock` (runtime AND development dependencies) against advisories.
Single job on PHP 8.3 (`ci.yml:224`). It must remain the **last job in the
file**: `tests/Unit/SecurityAuditCheckTest.php` slices ci.yml from
`composer-audit:` to EOF and fails on exclusion, `continue-on-error`, or any
neutering wrap (`ci.yml:207-212`).

**Failure means:** an advisory landed in the lock, or the gate was weakened.

## Build Gates (`.github/workflows/build.yml`)

### phar job (`build.yml:16-74`)

1. `composer install --no-dev` (`build.yml:34`), then `box compile` →
   `build/phlix.phar` (`build.yml:37`)
2. Smoke-tests the phar (`build.yml:39-53`) — three legs, and **no `help`
   invocation**: `php build/phlix.phar --version` (`:41`);
   `php build/phlix.phar bogus || test $? -eq 2` — the unknown-command probe
   must exit 2 (`:42`); and the stub-server selftest,
   `PHLIX_SERVER_URL=http://127.0.0.1:8099 php build/phlix.phar run --selftest`
   (`:46-53`), same in-step server pattern as the CI smoke job.
3. Generates `SHA256SUMS` (`sha256sum phlix.phar > SHA256SUMS` in `build/`,
   `build.yml:55-60`)
4. Uploads `phlix.phar` and `SHA256SUMS` as artifacts (`build.yml:62-74`)

### release-latest job (`build.yml:76-135`, on master push only)

Overwrites the rolling `latest` **prerelease** with the raw, directly
downloadable `phlix.phar` + `SHA256SUMS` (GitHub artifacts are always served
zipped; a Release is the only way to ship a bare runnable phar). Deletes and
recreates the tag so it points at this commit. Skipped on PRs and tag pushes.

### release-tag job (`build.yml:137-218`, on tag push `v*.*.*`)

1. Verifies `SHA256SUMS` integrity (`build.yml:170-174`)
2. Extracts version from tag and compares against `PHLIX_VERSION` in the PHAR
   (`build.yml:176-199`)
3. Publishes release with `phlix.phar` + `SHA256SUMS` (`build.yml:201-216`)

**Failure means:** Version number mismatch between tag and built artifact.

## PHP Version Matrix (complete job inventory)

| Job (`ci.yml`)               | 8.3 | 8.4 | 8.5 | matrix `fail-fast: false` |
|------------------------------|-----|-----|-----|---------------------------|
| test                         | ✓   | ✓   | ✓   | ✓ |
| phpstan (both legs)          | ✓   | ✓   | ✓   | ✓ |
| phpcs (both legs)            | ✓   | ✓   | ✓   | ✓ |
| smoke                        | ✓   | ✓   | ✓   | ✓ |
| i18n                         | ✓   | —   | —   | single-version job (`ci.yml:129`) |
| vendor-patches               | runner-default PHP — no setup-php step | | | |
| composer-audit (Security Audit) | ✓ | — | — | single-version job (`ci.yml:224`) |

All jobs with a matrix use `fail-fast: false` — all PHP versions run to
completion regardless of individual job results. `build.yml` runs its phar job
on PHP 8.3 only (`build.yml:26`).

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

*(Historical record as of C9; runs listed date 2026-08-07/10.)*

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
| Coverage floor      | 66% global / 70% PlayerScreen — **historical**: floor enforcement was removed 2026-08-22 (`09b4228`/`0e3dbce`); no coverage gate runs in CI today. See "test (PHPUnit)" above. |

*(Dated snapshot of the measurement taken when the C9.1 floors were set; the
figures themselves are kept verbatim as history.)*

## Dependencies

- `sugarcraft/*` packages are locked to `dev-master` (`composer.json:18-33,41`)
  — upstream carries no mainline tags (see `docs/dev/dependencies.md`).
- Dependency drift is detected weekly via `deps.yml` (Monday 06:00 UTC,
  `deps.yml:29-30`).
- The workflow **never writes master** (since the 2026-10-29 supply-chain
  repair `6fba622`): it runs phpstan, phpstan-tests, phpunit and the whole-lock
  security audit against the proposed lock, and only if all pass does it open a
  PR for a human to diff-review and merge (`deps.yml:53-90`; the workflow
  header forbids restoring a master-writing path, `deps.yml:17-27`).
