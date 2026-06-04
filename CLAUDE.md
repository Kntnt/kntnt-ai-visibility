# CLAUDE.md

Entry point for Claude Code working in this repository. It imports the universal
agent guidance and the coding standard; read the linked docs in `docs/` as the
task requires.

## Agent guidance

@AGENTS.md

## Coding standards

@docs/coding-standards.md

## When to load which doc

`docs/` holds the authoritative decisions. Load only what the task needs.

| Task | Read |
|---|---|
| Product brief, market, four-step plan | [`docs/Charter.md`](docs/Charter.md) |
| Anything touching the PHP version floor or the converter | [`docs/adr/0001-php-8-5-floor.md`](docs/adr/0001-php-8-5-floor.md), [`docs/adr/0002-vendor-converter-unscoped.md`](docs/adr/0002-vendor-converter-unscoped.md) |
| Distribution, updates, or releases | [`docs/adr/0003-github-is-the-1-0-distribution-channel.md`](docs/adr/0003-github-is-the-1-0-distribution-channel.md), [`docs/adr/0005-automated-tag-release-stable-asset.md`](docs/adr/0005-automated-tag-release-stable-asset.md) |
| Integration tests or the Playground harness | [`docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md`](docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md) |
| Module architecture / how `Plugin` wires modules | [`docs/adr/0006-deep-module-architecture.md`](docs/adr/0006-deep-module-architecture.md) |

## Project-specific conventions

The project follows the standard above verbatim, with the concrete instantiations
listed in [AGENTS.md](AGENTS.md) (namespace `Kntnt\Ai_Visibility`, slug/text-domain
`kntnt-ai-visibility`, prefix `kntnt_ai_visibility_`). There are no overrides beyond
pinning those placeholders and the four documented WP-CS deviations enforced in
`phpcs.xml.dist`.
