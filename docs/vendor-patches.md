# Vendor Patches

This directory captures hand-patched vendor files that have been modified from
their original Composer-distributed versions. These patches are applied as part
of the CI pipeline to ensure reproducible builds.

## Policy

**Do not hand-patch vendor files.** If you need a change to a dependency:

1. Fork the package and use `composer.json` `repositories` to point at your fork.
2. Submit a PR to the upstream package.
3. Only after the upstream accepts your PR, update the dependency version.

Hand-patching vendor files creates a divergence between the locked dependency
and what `composer install` would produce, which breaks reproducible builds and
makes dependency updates dangerous.

## Current Patches

At present, **no vendor files are hand-patched** in this project. The `patches/`
directory exists as a placeholder in case a patch becomes necessary in the future,
and the CI check (`vendor-patches-check`) confirms this remains true.

If a patch IS added, it should be named `{package-name}-{original-file}.patch`
(e.g., `sugar-gallery-PosterCard.php.patch`) and the CI check should be updated
to verify the patch applies cleanly.

## CI Check

The `vendor-patches-check` job in CI scans vendor/ for signs of hand-patching:

```bash
# Look for common patch markers left in source
grep -rE '(ORIGINAL|FIXED-BY|PATCH{|// PATCH|/\* PATCH)' vendor/ --include='*.php' && exit 1 || exit 0
```

This check will fail (blocking the build) if any PHP file in vendor/ contains
a pattern that suggests manual modification.

## When a Patch Is Unavoidable

If an upstream bug is blocking and a fork+PR is not fast enough:

1. Create the patch file in `patches/`
2. Document the reason, the upstream issue URL, and the expected resolution
3. Add a comment in `composer.json` under `extra.patches` explaining the patch
4. Update this document with the patch details
5. Set a calendar reminder to remove the patch when the upstream fix is released
