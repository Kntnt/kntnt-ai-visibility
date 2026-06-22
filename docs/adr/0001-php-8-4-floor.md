# PHP 8.4 as the minimum runtime version

The plugin bundles `kntnt/html-to-markdown` as its Markdown-conversion core and inherits that library's PHP floor. That floor is **PHP 8.4**, set by the native `Dom\HTMLDocument` HTML5 parser the converter relies on (introduced in 8.4).

An earlier revision pinned the floor at **8.5**, because the converter resolved relative URLs with the PHP-8.5-only `Uri\Rfc3986\Uri` class. Since Kntnt owns the converter, that single dependency was removed: `UrlResolver` now hand-ports RFC 3986 §5.2 reference resolution (mirroring Go's `net/url`), with byte-for-byte-identical output verified by the converter's golden fixtures. With `Uri\Rfc3986\Uri` gone, `Dom\HTMLDocument` is the only remaining floor driver, so the converter — and the plugin — run on PHP 8.4.

The floor remains **owner-controllable, not externally fixed**: Kntnt owns the converter. Lowering it below 8.4 would mean replacing `Dom\HTMLDocument` with another HTML5 parser, which is not currently planned (see [ADR-0004](0004-playground-e2e-no-auto-ddev-fallback.md)).

## Consequences

- Shipped code targets the PHP 8.4 feature set (typed class constants, property hooks, `Dom\HTMLDocument`). PHP-8.5-only syntax and built-ins — notably the `Uri` extension — must not be used. PHPStan's `phpVersion` is pinned to `80400` in both repositories to enforce this.
- As of mid-2026 a meaningfully larger share of live WordPress installs run PHP 8.4 than 8.5, so the floor reaches further. For 1.0 the reach concern is in any case secondary because distribution is GitHub-first (see [ADR-0003](0003-github-is-the-1-0-distribution-channel.md)), reaching technically capable users who can ensure a modern runtime. The trade-off remains accepting a byte-for-byte-correct, dependency-free converter over broad reach.
- The CI test matrix targets PHP 8.4 — the floor; there is no point testing lower versions the code cannot run on.
- `Requires PHP: 8.4` in the plugin header makes WordPress block activation on older installs, so failure is a clear admin notice rather than a fatal error.
