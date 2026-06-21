# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/). While the major version is `0`, the project makes no backwards-compatibility commitments: breaking changes can land in any release.

## [Unreleased]

### Added

- This changelog.
- `CONTRIBUTING.md` with contribution and pull-request guidance.
- README sections: *Questions, bugs, and feature requests*, *How you can contribute*, and *Changelog*.
- Modular architecture and design (a Core plus four feature modules) documented in `docs/architecture.md`, the `CONTEXT.md` glossary and architecture decision records under `docs/adr/`.
- The coding standard is now materialised in the repository under `agents.d/coding-standard/` – one module per language axis (general, PHP, WordPress and Bash) plus a private `manifest.json` snapshot – and loaded on demand rather than held in context every session.
- On-demand agent guides under `agents.d/`: `writing-standard.md`, `releasing.md` and `testing.md`, each linked from the `AGENTS.md` References index.

### Changed

- Documentation now follows British English via the `kntnt-text-skills:writing-rules en_GB` standard; the README uses spaced en-dashes ( – ) throughout.
- Bumped `actions/checkout` and `actions/setup-node` to v5 (Node 24 runtime).
- `AGENTS.md` slimmed to an always-loaded canon – authoritative ground rules, the non-obvious project facts and a References index – and `CLAUDE.md` reduced to a single `@AGENTS.md` bridge, cutting the always-loaded agent context by about 95 %.
- `README.md` and `CONTRIBUTING.md` now point at `agents.d/coding-standard/` for the coding standard and describe the agent-context files accurately.

### Removed

- `docs/coding-standards.md` – the monolithic coding standard, superseded by the on-demand modules under `agents.d/coding-standard/`.

## [0.1.0] – 2026-06-04

### Added

- Plugin bootstrap (`kntnt-ai-visibility.php`): PHP 8.5 floor guard with an admin notice and self-deactivation, PSR-4 autoloading, activation and deactivation hooks, and the `Plugin` singleton with an empty module-wiring seam.
- `Updater` for self-updates from GitHub Releases, pointed at the repository through the Plugin URI header.
- Lifecycle handlers: `install.php` (no-op for the scaffold), `uninstall.php` (removes the plugin's transients and settings option), and deactivation cleanup of transients.
- Tooling: Composer scripts (`test`, `stan`, `phpcs`, `phpcbf`, `build`), a Pest unit suite, PHPStan at level max, PHPCS (WordPress Coding Standards with four documented deviations), and the WordPress Playground end-to-end harness driven by `run-tests.sh`.
- Continuous integration (`.github/workflows/tests.yml`): lint, static analysis, unit tests with coverage ≥ 80 %, and Playground e2e on PHP 8.5, plus automated tag-to-release builds (`.github/workflows/release.yml`) that publish a version-less `kntnt-ai-visibility.zip` asset.

[Unreleased]: https://github.com/Kntnt/kntnt-ai-visibility/compare/0.1.0...HEAD
[0.1.0]: https://github.com/Kntnt/kntnt-ai-visibility/releases/tag/0.1.0
