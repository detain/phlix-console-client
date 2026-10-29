# Vendor Patches

This document describes the project's **policy** on hand-patching vendor files —
which the project forbids — and the CI check that enforces it. There is no
patches directory to "capture" anything: `vendor/` is Composer-managed and
untracked (`.gitignore` carries `/vendor/`), so a locally patched vendor file
has nowhere to be committed here and is silently overwritten by the next
`composer install` — one of the reasons the policy below exists.

## Policy

**Do not hand-patch vendor files.** If you need a change to a dependency:

1. Fork the package and use `composer.json` `repositories` to point at your fork.
2. Submit a PR to the upstream package.
3. Only after the upstream accepts your PR, update the dependency version.

Hand-patching vendor files creates a divergence between the locked dependency
and what `composer install` would produce, which breaks reproducible builds and
makes dependency updates dangerous.

## Current Patches

At present, **no vendor files are hand-patched** in this project. There is no
`patches/` directory — none has ever existed in this repository's history — and
there is no patch-applier tooling: `composer.json` requires no patch plugin and
its `extra` section has no `patches` key (which would be inert without one).
The CI check (the `vendor-patches` job) confirms this remains true on every
build.

## CI Check

The `vendor-patches` job in `.github/workflows/ci.yml` installs dependencies
with a plain `composer install` and then scans the resulting `vendor/` tree for
signs of hand-patching. It **applies nothing** — it only detects and fails.
The live detection step (quoted verbatim from the workflow):

```bash
PATCHED=$(grep -rEl '(FIXED-BY|PATCH{|// PATCH|/\* PATCH|\x1b\[1;31mPATCH)' vendor/ --include='*.php' 2>/dev/null || true)
```

If the list is non-empty the step prints the offending files and exits 1,
failing the build. The marker set is the alternation above: `FIXED-BY`,
`PATCH{`, `// PATCH`, `/* PATCH`, and an ANSI-red `PATCH`. Note that bare
`ORIGINAL` is deliberately **not** matched — vendor packages legitimately
contain constants and comments such as `COMPOSER_ORIGINAL_INIS` or
`ORIGINAL_NODE` (see the in-step comment in the workflow, and the same law
recorded in `docs/steps/ci-baseline-C9.md`).

## When a Patch Is Unavoidable

The sanctioned ladder is always the one under **Policy** above (fork +
`repositories`, upstream PR, version bump). There is no supported local-patch
pipeline in this repository: no `patches/` directory and no patch-applier
tooling exist today. If an upstream bug is blocking and a fork is not fast
enough, the only honest options are:

1. Point `composer.json` `repositories` at a patched fork (a normal Composer
   dependency on forked code — reproducible via the lock file), or
2. Carry the change as an upstream PR and wait.

Should the project ever adopt a real patch pipeline, it must land the tooling
(a `patches/` directory plus a CI step that applies and verifies the patches —
distinct from the marker grep above) in the same change that updates this
document. `tests/Docs/VendorPatchesDocsTest.php` pins the current state — no
patches, no tooling, grep-only enforcement — by deriving it from the workflow,
`composer.json`, `.gitignore`, and the filesystem at runtime, and reddens when
the world moves without the prose.
