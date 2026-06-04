# Zero-config defaults, a Core settings registry, one option

The plugin works **untouched**: the defaults in [ADR-0009](0009-markdown-alternate-serving-strategy.md) (every `publicly_queryable` post type, static-home `index.md`, no archives) need no configuration. Settings exist only to **override** defaults, never to set the plugin up.

## Mechanism

- **A Core settings registry, mirroring the artifact registry.** Each module registers its settings (fields, defaults, sanitisers) with a Core settings service; Core composes **one** server-side settings page with a **section per module**. Modules contribute, Core composes – the same deep-module shape as everything else. Server-side, no JS.
- **A single option: `kntnt_ai_visibility`.** All settings live in one option array, modules namespacing their own keys within it. No suffix – the project prefix already names it; `_settings`/`_options` would add nothing. (Genuinely separate structural state – a future migration/db-version or a cache-version stamp – may take its *own* clearly-named option key; that is a distinct concern, not a suffix on the settings array.)
- **Settings UI *and* developer filters.** The UI is primary (the "no technical setup" audience); filters are the programmatic escape hatch (e.g. post-type eligibility), as the behavioural-reference fork exposes.

## 1.0 content

The framework is not an empty shell in 1.0: the Markdown module contributes a real section – override the default post-type set, opt in archives/taxonomies and a clear-cache action. It is also roadmap-justified – llms.txt (release 2) and Content Signals (release 4) add their own sections – so building the registry now is the committed-roadmap seam, not speculation.
