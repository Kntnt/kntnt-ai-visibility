# AGENTS.md

Guidance for AI coding agents (Claude Code, Copilot, Cursor, Codex, …) working with code in this repository.

## Coding standards

@docs/coding-standards.md

## Project context

`kntnt-ai-visibility` is a WordPress plugin that makes content-rich websites (corporate sites, online magazines, blogs) discoverable, visible and readable by AI agents such as ChatGPT, Claude, Gemini and Perplexity — with zero configuration and no dependency on an SEO or e-commerce plugin. The 1.0 goal is four out-of-the-box features: (a) a Markdown version of each page via proper content negotiation, (b) auto-generated `llms.txt` + `llms-full.txt`, (c) RFC 8288/9727 Link headers for agent discovery, and (d) content signals in `robots.txt`. See [`docs/Charter.md`](docs/Charter.md) for the full position statement, vision, market analysis, and plan.

The plugin is built **modular**: a thin Core plus four feature modules, each a *deep module* with a thin boot contract, wired by the `Plugin` singleton in dependency order — see [`docs/adr/0006-deep-module-architecture.md`](docs/adr/0006-deep-module-architecture.md). This repository is currently at **step 1.1 — the scaffold**: repo + plugin bootstrap, autoloading, build/test/lint tooling, and CI. No module is implemented yet (the core and the Markdown module land in steps 1.4–1.5); the `Plugin` constructor has the wiring seam ready but empty. What exists today installs, lints, type-checks, and runs a green smoke-test suite.

## Architecture

**Singleton bootstrap.** `kntnt-ai-visibility.php` guards the PHP 8.5 floor (admin notice + self-deactivate), then requires `autoloader.php` (which delegates to Composer's PSR-4 autoloader), registers the activation/deactivation hooks, and calls `\Kntnt\Ai_Visibility\Plugin::get_instance(__FILE__)` inside a try/catch so a fatal during init is logged and surfaced as an admin notice instead of taking down the site. `classes/Plugin.php` is the singleton: it stores the main-file path, exposes the metadata helpers the `Updater` needs, clears the plugin's transients on deactivation, and is the single place where modules are wired (the constructor wires only the `Updater` today). `classes/Updater.php` is the GitHub-release self-updater, pointed at the repo via the Plugin URI header.

**Runtime dependency.** The Markdown conversion will use `kntnt/html-to-markdown` (namespace `Kntnt\HtmlToMarkdown\`), bundled **unscoped** in `vendor/` via `composer install --no-dev`. It requires PHP 8.5 (it uses `Uri\Rfc3986\Uri` and `Dom\HTMLDocument`), which is where the plugin's PHP floor comes from.

**Lifecycle.** `install.php` (activation) currently does nothing — the scaffold has no schema, capabilities, cron, or rewrite rules; modules wire their activation needs here. `uninstall.php` removes the plugin's transients and settings option. `Plugin::deactivate()` clears transients but preserves options.

## Authoritative decisions

The Charter and the ADRs are the source of truth — do not re-derive them.

| Decision | Read |
|---|---|
| Product brief, market, four-step plan | [`docs/Charter.md`](docs/Charter.md) |
| PHP 8.5 floor (owner-controllable to 8.4) | [`docs/adr/0001-php-8-5-floor.md`](docs/adr/0001-php-8-5-floor.md) |
| Bundle the converter unscoped in `vendor/` | [`docs/adr/0002-vendor-converter-unscoped.md`](docs/adr/0002-vendor-converter-unscoped.md) |
| GitHub (not wordpress.org) is the 1.0 channel | [`docs/adr/0003-github-is-the-1-0-distribution-channel.md`](docs/adr/0003-github-is-the-1-0-distribution-channel.md) |
| Playground e2e; **never** auto-fall-back to DDEV | [`docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md`](docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md) |
| Tag → auto-build → stable-named asset | [`docs/adr/0005-automated-tag-release-stable-asset.md`](docs/adr/0005-automated-tag-release-stable-asset.md) |
| Core + four deep modules, wired by `Plugin` | [`docs/adr/0006-deep-module-architecture.md`](docs/adr/0006-deep-module-architecture.md) |

**ADR-0004 is a hard rule:** if WordPress Playground cannot exercise a behaviour (e.g. php-wasm 8.5 lacking the `Uri` extension the converter needs), STOP and raise the fork to the maintainer — DDEV / lower the converter to PHP 8.4 / something else. Never wire an automatic DDEV fallback into tooling or CI.

## Naming conventions

| Context | Name |
|---|---|
| Plugin slug / repo / text domain | `kntnt-ai-visibility` |
| PHP namespace | `Kntnt\Ai_Visibility` |
| Option / transient / hook prefix | `kntnt_ai_visibility_` |
| GitHub repo | `Kntnt/kntnt-ai-visibility` |

Classes are `Pascal_Snake_Case`, mapped 1:1 to `classes/<Class_Name>.php` (PSR-4). Four deliberate deviations from WP-CS are enforced in `phpcs.xml.dist`: `[ ]` arrays, PSR-4 filenames, namespaces over function prefixes, and no required Yoda. Do not "correct" these toward upstream WP-CS.

## Pre-1.0 policy — no backwards compatibility

While the major version is `0`, **no design or implementation decision factors in existing users, installations, saved data, option shapes, or any other backwards compatibility.** There are no installs in the wild. Pick the cleanest end-state and ship the breaking change. If a prompt asks for a migration or deprecation while the version is still `0`, push back and explain this rule. It sunsets automatically when the `Version:` header crosses `1.0.0`.

## Tooling and tests

- `composer install` — dev dependencies. `composer test` — Pest unit suite. `composer stan` — PHPStan max. `composer phpcs` / `composer phpcbf` — coding standards. `composer build` — local runtime zip.
- `bash run-tests.sh [--unit-only|--e2e-only]` — Level 1 Pest unit; Level 2 WordPress Playground e2e (`@wp-playground/cli`, PHP 8.5). **No DDEV fallback** (ADR-0004).
- The Playground e2e harness lives in `tests/Integration/` (`blueprint.json`, `assert-boot.php`, `playground-smoke.sh`); the Pest `Integration` suite wraps it behind `KNTNT_RUN_PLAYGROUND=1` so plain `pest` stays offline.
- CI (`.github/workflows/tests.yml`) runs four jobs — lint, stan, unit (coverage ≥ 80 %), e2e — on PHP 8.5 for pushes and PRs to `main`.

## Cutting a release

Releases are automated (ADR-0005): bump the `Version:` header to `X.Y.Z`, commit, then push the tag `X.Y.Z`. `.github/workflows/release.yml` verifies the header matches the tag, runs `build-release-zip.sh`, and publishes `kntnt-ai-visibility.zip` (version-less name → stable `latest/download` URL) as the release asset. The build ships a production `vendor/` and excludes tests/CI/dev files.
