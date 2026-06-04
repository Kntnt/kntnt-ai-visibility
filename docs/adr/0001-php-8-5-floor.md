# PHP 8.5 as the minimum runtime version

The plugin bundles `kntnt/html-to-markdown` as its Markdown-conversion core, and that library requires PHP 8.5 — not as a `composer.json` preference but because it relies on built-ins that exist only in 8.5 (`Uri\Rfc3986\Uri` for relative-URL resolution) and 8.4 (`Dom\HTMLDocument` for HTML5 parsing). The plugin inherits that PHP 8.5 floor for 1.0.

The floor is **owner-controllable, not externally fixed**: Kntnt owns the converter and could lower it (and the plugin) to PHP 8.4 by replacing the single `Uri\Rfc3986\Uri` usage with an alternative URL-resolution approach. 8.5 is the chosen floor today, not an immovable one (see [ADR-0004](0004-playground-e2e-no-auto-ddev-fallback.md)).

## Consequences

- Shipped code may use the full PHP 8.5 feature set (the global Kntnt standard's default), because the runtime is guaranteed to be 8.5+. No back-compat carve-out is needed.
- As of mid-2026 only a small fraction of live WordPress installs run PHP 8.5. For 1.0 this reach concern is largely moot because distribution is GitHub-first (see [ADR-0003](0003-github-is-the-1-0-distribution-channel.md)), reaching technically capable users who can ensure a modern runtime. The trade-off is accepting a byte-for-byte-correct, dependency-free converter over broad reach.
- The CI test matrix targets PHP 8.5 only; there is no point testing lower versions the code cannot run on.
- `Requires PHP: 8.5` in the plugin header makes WordPress block activation on older installs, so failure is a clear admin notice rather than a fatal error.
