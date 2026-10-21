# Releases are cut by `/release`, and the build only builds the asset

Kntnt's `/release` Skill now releases every Kntnt project the same way (recorded as ADR-0246 in `Kntnt/skills`): it promotes `[Unreleased]` in `CHANGELOG.md`, bumps the `Version:` header in `kntnt-ai-visibility.php`, commits and pushes, and tags the commit `vX.Y.Z`. While `HEAD` carries that tag, it empties `release-assets/` and runs `bin/build-release-assets`; only then does it push the tag and create the GitHub release, with the changelog section for `X.Y.Z` as its notes and every file the build left there attached, in one step. The release workflow of [ADR-0005](0005-automated-tag-release-stable-asset.md) answered the same pushed tag by creating the same release, so the two raced. The workflow is removed and the repository adopts the convention: `.github/workflows/release.yml` is gone, `build-release-zip.sh` has become `bin/build-release-assets`, and the script builds `release-assets/kntnt-ai-visibility.zip` and never publishes. This supersedes ADR-0005 and [ADR-0011](0011-release-notes-from-changelog.md) where they describe how a release is made; what they decided about the release itself still stands, as listed below.

## What changes

- **No release in CI.** Pushing a tag builds nothing. A release is cut by running `/release` – the manual `gh` release that ADR-0005 deviated from, now performed the same way for every project by one Skill rather than by hand.
- **The build is the shared Kntnt script.** Only its plugin-specific block (plugin directory, keep-list, `prepare()`) belongs to this repository; the rest is identical in every Kntnt plugin. It builds from the committed state at `HEAD` with `git archive`, never from the working tree, so a build by hand and the release build produce the same files. The `--tag`, `--output`, `--create` and `--update` options and the `dist/` default are gone; `composer build` runs the script.
- **The notes are written by `/release`, not by the build.** The `awk` extraction in `build-release-zip.sh`, its `**Full changelog:**` footer and its fallback to `--generate-notes` are gone with the script's publishing.

## What still stands

From ADR-0005:

- The asset is `kntnt-ai-visibility.zip`, with no version segment, so the README's `latest/download` link stays permanent. The shared script names the zip after the plugin directory for the same reason. The `Updater` still identifies the asset by content type, not by name ([ADR-0003](0003-github-is-the-1-0-distribution-channel.md)).
- Release tags are `v`-prefixed (`vX.Y.Z`), which is also how `/release` tags.
- The `Version:` header at the tagged commit must equal the tag with its `v` stripped. The workflow enforced this; `bin/build-release-assets` now does, refusing to build a tagged `HEAD` whose header disagrees.
- The zip holds only runtime files, under a single `kntnt-ai-visibility/` directory, with a production `vendor/` from `composer install --no-dev --optimize-autoloader` ([ADR-0002](0002-vendor-converter-unscoped.md)). `composer.json` and `composer.lock` are exported for that install and removed before zipping, so they must never be marked `export-ignore` in `.gitattributes`: `git archive` would leave them out and the install would fail.

From ADR-0011: the release notes are the matching `## [X.Y.Z]` section of `CHANGELOG.md`, not GitHub's generated digest. Only who extracts the section has changed.

## Consequences

- A release appears with its zip already attached, so neither the `Updater` nor the `latest/download` link ever sees the newest release without it. If the build fails, nothing is published, and the tag stays on the machine that ran `/release`.
- Releasing depends on Kntnt's `/release` Skill rather than on a workflow inside this repository, so ADR-0011's "no dependency on any external script" no longer holds. Without the Skill, the same steps can be run by hand with `git`, `gh` and `bin/build-release-assets`.
- Built from `v0.7.1`, the new script reproduced that release's published asset exactly: the same entries, modes and file contents, `vendor/` included.
