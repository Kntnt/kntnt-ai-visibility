# kntnt-ai-visibility — agent guide

## Ground rules (authoritative)

Precedence over any conflicting skill, README or other doc unless the user overrides in the moment.

- Authoritative: only this file, the files it references and the actual code/state. Ignore `README*` and other narrative docs unless referenced here.
- Pre-1.0 (major `0`): ignore backwards-compat – no migrations, deprecations or saved-data/option-shape concerns; no installs exist. Push back if asked for them. Sunsets at `1.0.0`.
- Playground e2e only; NEVER auto-fall-back to DDEV (ADR-0004). If Playground cannot exercise a behaviour (e.g. a missing PHP extension or php-wasm limitation), STOP and raise to the maintainer.

## Non-obvious

- Namespace `Kntnt\Ai_Visibility`; slug / text-domain / repo `kntnt-ai-visibility`; option / transient / hook prefix `kntnt_ai_visibility_`; GitHub repo `Kntnt/kntnt-ai-visibility`.
- Classes are `Pascal_Snake_Case`, mapped 1:1 to `classes/<Class_Name>.php` (PSR-4).
- The PHP 8.4 floor comes from the bundled `kntnt/html-to-markdown` converter (needs `Dom\HTMLDocument`, the native HTML5 parser added in 8.4); owner-controllable – ADR-0001.
- Four WP-CS deviations are intentional and pinned in `phpcs.xml.dist`; `phpcbf` will not revert them – do not "fix" toward upstream WP-CS (list in `agents.d/coding-standard/wordpress.md`).

## References

- `agents.d/coding-standard/general.md` — before writing or changing any code
- `agents.d/coding-standard/php.md` — before writing or changing PHP
- `agents.d/coding-standard/wordpress.md` — before writing or changing a WordPress plugin or theme
- `agents.d/coding-standard/bash.md` — before writing or changing Bash
- `agents.d/writing-standard.md` — before writing or editing any Markdown in the repo
- `agents.d/releasing.md` — when cutting a release
- `agents.d/testing.md` — when running or changing the test suites
- `docs/architecture.md` — designed architecture: Core plus four deep modules, artifact model, request lifecycle
- `docs/spec/markdown-alternate.md` — concrete Release-1 contracts (Core slice + Markdown module); read before implementing
- `docs/spec/llms-txt.md` — concrete Release-2 contracts (llms.txt module + Core extensions: content-type matrix, exact-path router); read before implementing
- `docs/Charter.md` — product brief, market and the four-step plan
- `docs/adr/` — the authoritative decisions (ADR-0001 … 0010)
- `CONTEXT.md` — domain glossary
