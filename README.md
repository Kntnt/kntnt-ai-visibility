# Kntnt AI Visibility

[![Requires WordPress: 7.0+](https://img.shields.io/badge/WordPress-7.0+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.5+](https://img.shields.io/badge/PHP-8.5+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

A WordPress plugin that makes content-rich websites discoverable, visible and readable by AI agents – with zero configuration and no dependency on an SEO or e-commerce plugin.

## Description

For content-rich websites – corporate sites, online magazines and blogs – that want to be found and accurately represented by AI agents such as ChatGPT, Claude, Gemini and Perplexity, Kntnt AI Visibility makes your entire site discoverable, visible and readable to those agents, and puts you in control of how your content may be used. Unlike tools that each solve only one piece of the puzzle – and are often built for e-commerce or dependent on a separate SEO plugin – Kntnt AI Visibility is built exclusively for content sites: simple, complete and dependency-free.

### Features

Kntnt AI Visibility is built around four capabilities, none of which depends on an SEO or e-commerce plugin. Two are available today; the other two are still to come.

1. **Markdown alternates** *(available)* – a clean Markdown version of every page, served on its canonical URL through `Accept` negotiation, and also reachable as a `.md` URL or via `?format=markdown`, produced by a high-fidelity HTML-to-Markdown converter and cached to disk for fast serving.
2. **`llms.txt` and `llms-full.txt`** *(available)* – `/llms.txt`, a curated index of your key content that links to each page's Markdown, and `/llms-full.txt`, your selected pages concatenated into a single Markdown document. Both are generated on first request and rebuilt as your content changes.
3. **Link headers** *(planned)* – RFC 8288/9727 headers that advertise the Markdown alternates and `llms.txt` so agents can find them.
4. **Content signals in `robots.txt`** *(planned)* – declare how AI agents may use your content.

Which content each file exposes is set on a single settings page – one row per content type, one column per file – and the zero-config defaults work without any setup. See [`docs/Charter.md`](docs/Charter.md) for the full plan.

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.5 |
| WordPress | 7.0 |

The plugin checks the PHP version on activation and aborts with a clear admin notice if the requirement is not met. WordPress blocks activation on versions older than 7.0.

## Installation

1. [Download the latest release ZIP](https://github.com/Kntnt/kntnt-ai-visibility/releases/latest/download/kntnt-ai-visibility.zip).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the ZIP and install it.
3. Activate the plugin.

The plugin is distributed via GitHub Releases and updates through the standard WordPress plugin-update UI: when a new version is released, it appears on the **Updates** page like any other plugin. (Distribution is GitHub-first by design – see [`docs/adr/0003`](docs/adr/0003-github-is-the-1-0-distribution-channel.md).)

## Serving cached Markdown directly (optional)

The plugin already serves each Markdown alternate efficiently: it writes a cache file on the first request and, on every later request, an early router streams that file from disk and skips the rest of the WordPress lifecycle. For maximum performance you can go one step further and let your web server serve the cached file *without invoking PHP at all*, falling back to WordPress only when no cache file exists yet. This is entirely optional – the plugin works fully without it – and it is left to the server owner because the configuration is server-specific (see [`docs/adr/0007`](docs/adr/0007-file-cached-artifacts-early-contained-router.md)).

The mapping is direct: a request for `/<path>.md` is served from `wp-content/uploads/kntnt-ai-visibility-cache/markdown-alternate/<path>.md` when that file exists (for example `/about/team.md` → `…/markdown-alternate/about/team.md`, and the home `/index.md` → `…/markdown-alternate/index.md`). Only public, published content is ever cached, so serving these files directly is safe.

The snippets below are **starting points to adapt and test** against your own setup – adjust the cache path if your uploads directory is non-standard, and make sure the rules run *before* your existing WordPress/PHP handling.

**nginx** – inside your site's `server` block:

```nginx
location ~ \.md$ {
    default_type text/markdown;
    charset utf-8;
    charset_types text/markdown;
    try_files /wp-content/uploads/kntnt-ai-visibility-cache/markdown-alternate$uri /index.php?$args;
}
```

**Apache** – in the WordPress root `.htaccess`, *above* the `# BEGIN WordPress` block (requires `mod_rewrite` and `mod_headers`):

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} \.md$
    RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/kntnt-ai-visibility-cache/markdown-alternate%{REQUEST_URI} -f
    RewriteRule ^(.+)$ /wp-content/uploads/kntnt-ai-visibility-cache/markdown-alternate/$1 [L]
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.md$">
        ForceType "text/markdown; charset=utf-8"
    </FilesMatch>
</IfModule>
```

When no cached file exists – the first request after a change, or content that was just invalidated – the request falls through to WordPress, which regenerates and serves it (and writes the cache for next time).

The two llms.txt singletons – `/llms.txt` and `/llms-full.txt` – are cached and early-served the same way: a warm request streams straight from its cache file and skips the rest of the WordPress lifecycle, exactly like a `.md`. The no-PHP static tier above is *not* practical for them, though, because their cache filename carries a version stamp – `…/kntnt-ai-visibility-cache/llms-txt/llms-v{N}.md` and `…/llms-full/llms-full-v{N}.md`, where `N` is the current cache-version (bumped whenever content changes) – and a static `try_files` rule cannot resolve that `N`. So the singletons stay early-served from the PHP cache; only the per-page `.md` files are eligible for the server-only tier above.

## Questions, bugs and feature requests

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

The [`docs/`](docs/) directory is the authoritative technical record: [`docs/Charter.md`](docs/Charter.md) for the product brief, market analysis and four-step plan; the Architecture Decision Records in [`docs/adr/`](docs/adr/) for the decisions behind the design; and [`agents.d/coding-standard/`](agents.d/coding-standard/) for the coding standard. [`CLAUDE.md`](CLAUDE.md) and [`AGENTS.md`](AGENTS.md) are the entry point for AI coding assistants: `CLAUDE.md` bridges to `AGENTS.md`, which holds the always-loaded canon – authoritative ground rules plus the non-obvious project facts – and a References index that points on demand to the coding standard in [`agents.d/`](agents.d/) and to the documents above. Both are equally readable for human contributors.

## How you can contribute

Contributions are welcome, large or small – reporting a bug or requesting a feature through an issue, opening a pull request, improving the documentation or translating the plugin into another language. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for how to set up the project, the quality gates your change needs to pass and the pull-request process.

## License

[GPL-2.0-or-later](LICENSE).

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).

The project follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/).
