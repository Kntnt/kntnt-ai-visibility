# Kntnt AI Visibility

[![Requires WordPress: 7.0+](https://img.shields.io/badge/WordPress-7.0+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.5+](https://img.shields.io/badge/PHP-8.5+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

A WordPress plugin that makes content-rich websites discoverable, visible and readable by AI agents – with zero configuration and no dependency on an SEO or e-commerce plugin.

## Description

For content-rich websites – corporate sites, online magazines and blogs – that want to be found and accurately represented by AI agents such as ChatGPT, Claude, Gemini and Perplexity, Kntnt AI Visibility makes your entire site discoverable, visible and readable to those agents, and puts you in control of how your content may be used. Unlike tools that each solve only one piece of the puzzle – and are often built for e-commerce or dependent on a separate SEO plugin – Kntnt AI Visibility is built exclusively for content sites: simple, complete and dependency-free.

### Planned features (1.0)

The 1.0 release will provide four features out of the box, without relying on any other plugin:

1. **Markdown via content negotiation** – a clean Markdown version of each page, served on the canonical URL through `Accept` negotiation (with a `.md` and `?format=markdown` fallback), using a high-fidelity HTML-to-Markdown converter.
2. **`llms.txt` and `llms-full.txt`** – automatically generated and kept up to date.
3. **Link headers** – RFC 8288/9727-compliant headers that advertise the Markdown alternates and the `llms.txt` file for agent discovery.
4. **Content signals in `robots.txt`** – declare your AI usage preferences.

> **Project status:** this repository is at **step 1.1 – the scaffold** (plugin bootstrap, autoloading, build/test/lint tooling, and CI). The features above are implemented in later steps; see [`docs/Charter.md`](docs/Charter.md) for the full plan.

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.5 |
| WordPress | 7.0 |

The plugin checks the PHP version on activation and aborts with a clear admin notice if the requirement is not met. WordPress blocks activation on versions older than 7.0.

## Installation

1. [Download the latest release ZIP](https://github.com/Kntnt/kntnt-ai-visibility/releases/latest/download/kntnt-ai-visibility.zip).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and install it.
3. Activate the plugin.

The plugin is distributed via GitHub Releases and updates through the standard WordPress plugin-update UI: when a new version is released, it appears on the **Updates** page like any other plugin. (Distribution is GitHub-first by design – see [`docs/adr/0003`](docs/adr/0003-github-is-the-1-0-distribution-channel.md).)

## Questions, bugs, and feature requests

Have a usage question or something to discuss? Please use [Discussions](https://github.com/Kntnt/kntnt-ai-visibility/discussions).

Found a bug or want to request a feature? Please [open an issue](https://github.com/Kntnt/kntnt-ai-visibility/issues). Search the existing issues first to avoid duplicates.

## Development

### Getting started

```bash
git clone https://github.com/Kntnt/kntnt-ai-visibility.git
cd kntnt-ai-visibility
composer install
```

### Quality gates

```bash
composer phpcs     # WordPress Coding Standards (with the documented deviations)
composer stan      # PHPStan at level max
composer test      # Pest unit suite
bash run-tests.sh             # Level 1 (Pest) + Level 2 (Playground e2e)
bash run-tests.sh --unit-only # Level 1 only
```

Level 2 boots the plugin in [WordPress Playground](https://wordpress.github.io/wordpress-playground/) on PHP 8.5 via `@wp-playground/cli` (needs Node.js). There is deliberately **no** automatic DDEV fallback – see [`docs/adr/0004`](docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md).

### Building a release ZIP locally

```bash
bash build-release-zip.sh --output .   # → kntnt-ai-visibility.zip in the current dir
bash build-release-zip.sh --help
```

The script runs `composer install --no-dev --optimize-autoloader` in a staging directory and packages only the runtime files (main file, `autoloader.php`, `classes/`, `vendor/`, `languages/`, `install.php`, `uninstall.php`, `README.md`, `LICENSE`). Your working tree is untouched.

### Releasing

Pushing a version tag `X.Y.Z` triggers [`.github/workflows/release.yml`](.github/workflows/release.yml), which builds the ZIP and publishes it as `kntnt-ai-visibility.zip` on the GitHub release. The version-less asset name is what makes the `latest/download` link above permanent (see [`docs/adr/0005`](docs/adr/0005-automated-tag-release-stable-asset.md)). The `Version:` header must match the tag.

### Technical documentation

The [`docs/`](docs/) directory is the authoritative technical record: [`docs/Charter.md`](docs/Charter.md) for the product brief, market analysis, and four-step plan; the Architecture Decision Records in [`docs/adr/`](docs/adr/) for the decisions behind the design; and [`agents.d/coding-standard/`](agents.d/coding-standard/) for the coding standard. [`CLAUDE.md`](CLAUDE.md) and [`AGENTS.md`](AGENTS.md) are the entry point for AI coding assistants: `CLAUDE.md` bridges to `AGENTS.md`, which holds the always-loaded canon – authoritative ground rules plus the non-obvious project facts – and a References index that points on demand to the coding standard in [`agents.d/`](agents.d/) and to the documents above. Both are equally readable for human contributors.

## How you can contribute

Contributions are welcome, large or small – reporting a bug or requesting a feature through an issue, opening a pull request, improving the documentation, or translating the plugin into another language. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for how to set up the project, the quality gates your change needs to pass, and the pull-request process.

## License

[GPL-2.0-or-later](LICENSE).

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).

The project follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/).
