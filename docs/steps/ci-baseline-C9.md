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
