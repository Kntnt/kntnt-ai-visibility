# Kntnt AI Visibility

[![Requires WordPress: 6.7+](https://img.shields.io/badge/WordPress-6.7+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.4+](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

A WordPress plugin that makes content-rich websites discoverable, visible and readable by AI agents – with zero configuration and no dependency on an SEO or e-commerce plugin.

## Description

For content-rich websites – corporate sites, online magazines and blogs – that want to be found and accurately represented by AI agents such as ChatGPT, Claude, Gemini and Perplexity, Kntnt AI Visibility makes your entire site discoverable, visible and readable to those agents, and puts you in control of how your content may be used. Unlike tools that each solve only one piece of the puzzle – and are often built for e-commerce or dependent on a separate SEO plugin – Kntnt AI Visibility is built exclusively for content sites: simple, complete and dependency-free.

### Features

Kntnt AI Visibility is built around four capabilities, none of which depends on an SEO or e-commerce plugin.

1. **Markdown alternates** – a clean Markdown version of each eligible page, produced by a high-fidelity HTML-to-Markdown converter. A `.md` URL or `?format=markdown` serves a file-cached alternate; the canonical URL serves fresh, uncached Markdown only when the client's `Accept` header explicitly prefers it to HTML.
2. **`llms.txt` and `llms-full.txt`** – `/llms.txt`, a curated index of your key content that links to each page's Markdown, and `/llms-full.txt`, your selected pages concatenated into a single Markdown document. Both are generated on first request and rebuilt as your content changes.
3. **Link headers** – RFC 8288/9727 headers that advertise the Markdown alternates and `llms.txt` so agents can find them.
4. **Content signals in `robots.txt`** – declare how AI agents may use your content.

Which content each file exposes is set on a single settings page – one row per content type, one column per file – with an **Excluded paths** field that curates out individual pages by URL pattern (one regular expression per line, matched against each page's path). The zero-config defaults work without any setup. See [`docs/Charter.md`](docs/Charter.md) for the full plan.

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.4 |
| WordPress | 6.7 |

The plugin checks the PHP version on activation and aborts with a clear admin notice if the requirement is not met. WordPress blocks activation on versions older than 6.7.

## Installation

1. [Download the latest release ZIP](https://github.com/Kntnt/kntnt-ai-visibility/releases/latest/download/kntnt-ai-visibility.zip).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the ZIP and install it.
3. Activate the plugin.

The plugin is distributed via GitHub Releases and updates through the standard WordPress plugin-update UI: when a new version is released, it appears on the **Updates** page like any other plugin. (Distribution is GitHub-first by design – see [`docs/adr/0003`](docs/adr/0003-github-is-the-1-0-distribution-channel.md).)

## Content negotiation and cache protection

On an eligible page's canonical URL, `GET` and `HEAD` requests select Markdown only when an explicit `text/markdown` or `text/x-markdown` media range has a positive quality weight (`q`) strictly greater than the effective weight of either HTML alternative (`text/html` and `application/xhtml+xml`). An omitted weight means `q=1`. Equal weights keep HTML; wildcard ranges alone never select Markdown, and malformed or zero Markdown weights do not select it.

| `Accept` header | Canonical response |
|---|---|
| `text/markdown` | Markdown |
| `text/html;q=0.8, text/markdown;q=0.9` | Markdown |
| `text/html, text/markdown;q=0.9` | HTML |
| `text/html, text/markdown` | HTML |
| `*/*` | HTML |
| `text/markdown;q=0` | HTML |

Negotiated Markdown is rendered for an anonymous visitor and is never written to the artifact cache. Its `200` and `304` responses send `Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate` and `Vary: Accept`, preserving other existing `Vary` values. The plugin also sets WordPress's `DONOTCACHEPAGE` convention early in the request. This protection is independent of any particular cache plugin.

An explicit `.md` URL or `?format=markdown` request takes precedence over `Accept` negotiation and retains its separate, cacheable representation. With plain permalinks, use the query form. When reactivating the plugin after the earlier negotiation bug, purge shared page caches and verify the affected URLs; a cache that serves before WordPress needs its own bypass rule. The [cache deployment guide](docs/operations/negotiated-cache.md) describes this verification and the optional integration hooks.

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

## Extending

Everything the plugin produces – the Markdown alternates, `/llms.txt`, `/llms-full.txt` and the `robots.txt` content signals – can be customised through optional WordPress filters and actions, all prefixed `kntnt_ai_visibility_`. You can add a custom post type, rewrite an index entry's title and description from your SEO plugin or set the `robots.txt` content-signal policy.

For themes that build their visible body from fields such as ACF, a site adapter can supply that public HTML through `kntnt_ai_visibility_public_content_html`. The same body pipeline feeds explicit alternates, negotiated Markdown and `llms-full.txt`. The adapter declares rendered field dependencies through `kntnt_ai_visibility_public_content_meta_keys` and signals committed indirect changes through `kntnt_ai_visibility_indirect_content_changed`; arbitrary metadata is never automatically exposed.

Cache adapters can listen to `kntnt_ai_visibility_cache_bypass` and veto private content during rendering through `kntnt_ai_visibility_public_content_nocache`. Vendor-specific API calls belong in the site's own plugin. For example, SafeTeam's optional Sceleton and LiteSpeed integrations live in `kntnt-safeteam`; AI Visibility has no dependency on that plugin or LiteSpeed.

The full reference – hook signatures, rendering and invalidation contracts and worked examples – is in [`docs/EXTENSIBILITY.md`](docs/EXTENSIBILITY.md).

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

Level 2 boots the plugin in [WordPress Playground](https://wordpress.github.io/wordpress-playground/) on PHP 8.4 via `@wp-playground/cli` (needs Node.js). There is deliberately **no** automatic DDEV fallback – see [`docs/adr/0004`](docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md).

### Building a release ZIP locally

```bash
bash build-release-zip.sh --output .   # → kntnt-ai-visibility.zip in the current dir
bash build-release-zip.sh --help
```

The script runs `composer install --no-dev --optimize-autoloader` in a staging directory and packages only the runtime files (main file, `autoloader.php`, `classes/`, `vendor/`, `languages/`, `install.php`, `uninstall.php`, `README.md`, `LICENSE`). Your working tree is untouched.

### Releasing

Pushing a version tag `vX.Y.Z` triggers [`.github/workflows/release.yml`](.github/workflows/release.yml), which builds the ZIP and publishes it as `kntnt-ai-visibility.zip` on the GitHub release. The version-less asset name is what makes the `latest/download` link above permanent (see [`docs/adr/0005`](docs/adr/0005-automated-tag-release-stable-asset.md)). The `Version:` header must match the tag without its `v` prefix.

### Technical documentation

Start with [`docs/architecture.md`](docs/architecture.md) for the module boundaries, [`docs/spec/markdown-alternate.md`](docs/spec/markdown-alternate.md) for request negotiation and public rendering contracts, [`docs/EXTENSIBILITY.md`](docs/EXTENSIBILITY.md) for integration hooks and [`docs/operations/negotiated-cache.md`](docs/operations/negotiated-cache.md) for shared-cache deployment checks. [`docs/Charter.md`](docs/Charter.md) records the product plan and [`docs/adr/`](docs/adr/) records the design decisions.

[`CLAUDE.md`](CLAUDE.md) bridges to [`AGENTS.md`](AGENTS.md), the entry point for AI coding assistants. Its ground rules, references and the actual code/state define the authoritative project context; [`agents.d/`](agents.d/) contains the coding, writing, testing and release instructions. Human contributors can use the same references.

## How you can contribute

Contributions are welcome, large or small – reporting a bug or requesting a feature through an issue, opening a pull request, improving the documentation or translating the plugin into another language. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for how to set up the project, the quality gates your change needs to pass and the pull-request process.

## License

[GPL-2.0-or-later](LICENSE).

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).

The project follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/).
