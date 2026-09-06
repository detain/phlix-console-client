---
name: server-route-manifest-gate
description: The vendored phlix-server route manifest and the gate test that pins every URL the console can put on the wire, plus how to re-vendor it.
paths:
  - src/Api/**/*.php
  - tests/Unit/Api/ServerRouteManifestGateTest.php
  - tests/Unit/Api/GateScanner.php
  - tests/fixtures/server-route-manifest.json
---

# Server route manifest gate

`tests/fixtures/server-route-manifest.json` is a **byte-for-byte vendored copy** of the
`@phlix/contracts` server route manifest (400 `[method, pathTemplate]` tuples). Never
hand-edit it — re-vendor it verbatim. A manifest derived from the client it checks would
self-adjust and pass every defect it exists to catch.

`tests/Unit/Api/ServerRouteManifestGateTest.php` asserts every URL the console can put on
the server wire is tuple-exact against that manifest;
`tests/Unit/Api/GateScanner.php` is the `token_get_all` request-site scanner it drives
(not a test class — no `Test` suffix, autoloaded via the dev PSR-4 map).

- Matching is **segment-exact, never substring**: `/media/{id}` can never absorb
  `/media/{id}/markers`, and dynamic client parts canonicalise to `{P}`.
- The coverage numbers are **pins, not promises** — per-class anchor counts, per-helper
  suffix expansions, the `CastBackend`-derived cast surface, the per-path hub negatives,
  the SyncPlay WebSocket pin, and the per-file `/api/v1` token sweep all go red on drift.
- A new rail in `src/Api/ApiClient.php` (or any `src/Api` client) must exist in the
  manifest **and** have its site counts re-pinned by measurement in the test's constants.

## Re-vendoring

Copy the new manifest in verbatim, then move its provenance pins together in
`tests/Unit/Api/ServerRouteManifestGateTest.php`: `EXPECTED_MD5`, `EXPECTED_SERVER_SHA`,
the tuple total, and the `contracts@<sha>` citation in the failure message. Record the
contracts + server SHAs in `CHANGELOG.md`.

```sh
vendor/bin/phpunit tests/Unit/Api/ServerRouteManifestGateTest.php
```
