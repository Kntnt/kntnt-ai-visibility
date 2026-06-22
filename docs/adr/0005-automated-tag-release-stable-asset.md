# Releases are automated on version tags, with a stable-named asset

Pushing a version tag (`vX.Y.Z`) triggers a GitHub Actions workflow that builds the distribution ZIP and attaches it to that tag's GitHub release — no manual release step. The asset is named after the repository with **no version segment** (`kntnt-ai-visibility.zip`), so the README can link once to `…/releases/latest/download/kntnt-ai-visibility.zip` and that URL forever resolves to the newest release. This deviates from the reference repos (gpx-blocks, ad-attribution-gads), which release manually with `gh`.

**Tag format.** Release tags are `v`-prefixed (`vX.Y.Z`); the workflow trigger matches `v[0-9]+.[0-9]+.[0-9]+` and the header-versus-tag check strips the `v` to compare against the `Version:` header. The first release (`0.1.0`) predates this and is tagged bare; every release from `v0.2.0` on carries the prefix. **Release notes** are taken from the matching `CHANGELOG.md` section, not GitHub's auto-generated digest — see [ADR-0011](0011-release-notes-from-changelog.md).

## Single build script, two triggers

Build logic lives in **one** script (an adaptation of ad-attribution-gads' `build-release-zip.sh`), invoked two ways so the logic is never duplicated:

- **CI (release):** on a version tag, the workflow checks out the tag, runs `composer install --no-dev --optimize-autoloader`, and invokes the script to produce and upload the asset.
- **Local (testing):** the same script with `--output .` (exposed as a `composer build` alias) drops `kntnt-ai-visibility.zip` in the working directory, built from the **local working copy** — for manual upload/testing on a local WordPress site before tagging a real release.

The ZIP contains only runtime files (main file, `autoloader.php`, `classes/`, runtime `vendor/`, `js`/`css`, `languages`, `install.php`/`uninstall.php`, `README`, `LICENSE`) via an explicit keep-list; dev files (tests, CI, dev-composer, dotfiles, the build script) are excluded.

## Consequences

- The version-less filename is what enables the permanent `latest/download` link; it must not gain a version segment.
- The `Version:` header at the tagged commit must match the tag with its `v` stripped (release discipline; the workflow enforces it).
- The build must run `composer install --no-dev` so the shipped `vendor/` includes the runtime converter ([ADR-0002](0002-vendor-converter-unscoped.md)).
- The GitHub-release `Updater` ([ADR-0003](0003-github-is-the-1-0-distribution-channel.md)) identifies the asset by content-type, not filename, so the stable name is compatible with self-update.
