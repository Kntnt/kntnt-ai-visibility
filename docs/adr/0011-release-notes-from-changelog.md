# Release notes come from the CHANGELOG

The GitHub release created on a version tag takes its body from the matching `CHANGELOG.md` section, not from GitHub's `--generate-notes` (the commit-and-PR digest used until now). The changelog is hand-curated, user-facing and already grouped into *Added* / *Changed* / *Removed*; auto-generated notes are a raw commit list that repeats the changelog's raw material in a worse form. Sourcing the release body from the changelog gives one authoritative, reader-quality account of each release and keeps the tag, the release and the changelog telling the same story. This refines [ADR-0005](0005-automated-tag-release-stable-asset.md), which established the automated tag-to-release build but left the notes auto-generated.

## How it works

`build-release-zip.sh --create` extracts the `## [X.Y.Z]` section from `CHANGELOG.md` at the tagged commit and passes it to `gh release create` via `--notes-file`. The version is the tag with its `v` prefix stripped (the tag is `vX.Y.Z`, the changelog heading is `[X.Y.Z]` — see the tag-format note in [ADR-0005](0005-automated-tag-release-stable-asset.md)). The extraction stops at the next version heading or the reference-link block, so it captures exactly that release's entries.

## Consequences

- The changelog discipline is now release-blocking in spirit: a release with an empty or stale `## [X.Y.Z]` section ships empty notes. The `push` and `release` skills keep `[Unreleased]` reconciled so the section is accurate before it is promoted and tagged.
- The release body mirrors the changelog verbatim (heading levels and all); no second, divergent prose is written for the release.
- No dependency on any external script: the extraction is a few lines of `awk` inside the repo's own `build-release-zip.sh`, so CI stays self-contained.
