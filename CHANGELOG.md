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
- Step 1.3 specification for Release 1 – the Markdown alternate – with concrete Core-slice and module contracts in `docs/spec/markdown-alternate.md`.
- Core foundation for Release 1: the `Module` boot contract and the `Core` service facade; an artifact-provider registry with its identity, request, serve-pattern and link-relation value objects; a file-backed artifact cache and an early, hardened serve router that contains every request inside the cache directory (adversarially tested against path traversal, encoded traversal, null bytes and symlink escape); a zero-config settings registry over the single `kntnt_ai_visibility` option; and a visitor-silent logger.
- Markdown-alternate generation: the shared Page-Markdown service that renders a post through `the_content`, converts the HTML to GitHub-Flavored Markdown (tables and strikethrough included) with relative URLs absolutised against the site, and assembles the YAML front-matter (`title`, `canonical_url`, `date`, `author`, and conditional `featured_image`, `categories`, `tags`) followed by the page's visible H1 and body, with single-flight caching; plus the Markdown-alternate provider and its eligibility rule (single, public, published, front-end-viewable entries — including pages, posts and public custom post types — with the static-home `/index.md`), resolving the target via `url_to_postid()` with hierarchical-page and published-post-slug fallbacks so a `.md` request still resolves when the rewrite has steered WordPress's main query to the front page.
- Markdown-alternate serving and discovery: the request handler with strict content negotiation (`.md` URL > `?format=markdown` > `Accept: text/markdown`), the inline uncached `Accept` form carrying `Vary: Accept` and a steering alternate link, `Content-Type`/`Content-Length`/`Last-Modified`/`ETag` headers with conditional `304`s, a `403` for password-protected content, a `301` for a trailing-slashed `.md` URL, and canonical-redirect suppression; the per-page `<link rel="alternate" type="text/markdown">` discovery tag on `wp_head`; delete-on-change invalidation (per entry on save and status transition, whole-cache flush on theme switch and settings change) with a cache-version stamp; and a filterable TTL safety net on the serve router.
- The Markdown-alternate module is now wired into the plugin: the `Plugin` bootstrap builds the Core service graph and boots the module, and the early serve router runs from the main plugin file before WordPress routing — a cached `.md` request is served straight from disk and the WordPress lifecycle skipped, while a miss falls through to lazy generation.
- A Markdown settings section on the plugin's settings page: a post-type override (comma-separated slugs; leave it empty to expose every public post type, mirrored by the `kntnt_ai_visibility_markdown_post_types` filter) and a *Clear Markdown cache* button that flushes every cached file.
- A behavioural WordPress Playground end-to-end test (`tests/Integration/playground-e2e.sh`) that boots a real Playground HTTP server with the plugin mounted and fixtures seeded, then drives the `.md` request lifecycle over HTTP — a real `.md` (200, `text/markdown`, front-matter, the converted and absolutised body), `?format=markdown`, `Accept` negotiation (`Vary`, the steering alternate `Link`), `/index.md`, a 404 for ineligible content, a 403 for password-protected content, a 301 for a trailing slash, and path-traversal payloads that never leak — wired into `run-tests.sh` and the CI e2e job alongside the boot smoke test.
- Subdirectory-install support: cache keys and request resolution are taken relative to the WordPress home (the serve router strips the same base path), so both per-page `.md` and the home `/index.md` resolve and cache correctly when WordPress lives under a path such as `/blog/`, not only at the domain root.
- README section *Serving cached Markdown directly* documenting the optional web-server tier (nginx and Apache `try_files` examples) that serves the cached `.md` files straight from disk, bypassing PHP entirely, with a fall-through to WordPress on a miss.

### Changed

- Activation now registers the `.md` rewrite rules and flushes them once; deactivation flushes the rewrite rules and clears the file cache while preserving the settings option; uninstall deletes the `kntnt_ai_visibility` option and the cache-version option and removes the file cache directory — replacing the scaffold's transient-based cleanup, since the cache is files under uploads rather than transients.

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
