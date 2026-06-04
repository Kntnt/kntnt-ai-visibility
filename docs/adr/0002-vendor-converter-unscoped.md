# Bundle the Markdown converter as an unscoped runtime dependency

The plugin requires `kntnt/html-to-markdown` at runtime (not dev-only) and ships it inside the release `vendor/` via the standard `composer install --no-dev --optimize-autoloader` build. We deliberately do **not** run PHP-Scoper/Strauss to prefix the library's namespace; it ships bare under `Kntnt\HtmlToMarkdown\`.

## Considered options

- **Scope (PHP-Scoper/Strauss).** Prefixes the bundled namespace to something unique, making same-site collisions impossible — at the cost of a real build step and a scoped autoloader, friction the plugin's "simple, dependency-free" ethos resists.
- **Ship bare (chosen).** No extra build machinery. The only collision window is narrow and self-owned: it triggers only if two *Kntnt* plugins bundle *different versions* of *our own* stable library on the same site — both sides of which we control.

## Consequences

- If a future second Kntnt plugin bundles a different version of `kntnt/html-to-markdown` alongside this one, PHP loads whichever registers first — a potential version-skew bug. Scoping is the clean fix and is kept in reserve for that scenario.
- 1.0 keeps a composer-only build with no scoping toolchain.
