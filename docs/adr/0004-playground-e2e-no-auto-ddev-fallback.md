# e2e tests run on WordPress Playground; no automatic DDEV fallback

End-to-end tests use **WordPress Playground** (WASM PHP, `@wp-playground/cli`), wired as a second level in `run-tests.sh` and `tests/Integration/`. Playground supports the plugin's PHP 8.4 floor, so it can boot the plugin. This satisfies Charter step 1.4 ("unit and e2e tests") and follows the `coder` standard's "Playground default" rule.

## The escalation rule (deviation from the standard)

The `coder` standard says to fall back to DDEV when Playground can't exercise a behaviour (e.g. a missing PHP extension). We deliberately **do not** auto-fall-back here. The original motivating risk — that php-wasm's build might not bundle the `Uri\Rfc3986\Uri` extension the converter once used for relative-URL resolution — is now moot: the converter hand-ports RFC 3986 resolution and depends on no such extension (see [ADR-0001](0001-php-8-4-floor.md)). The rule still stands for any *future* gap: if a missing extension or behaviour makes Playground unviable, the question is **lifted to the maintainer for a decision**, not resolved automatically.

The decision space at that fork was at least three-way, because Kntnt **owns** the converter:

1. Use DDEV for the integration layer (the standard's default fallback).
2. Lower the converter — and thus the plugin — to **PHP 8.4**, replacing the `Uri\Rfc3986\Uri` usage with an alternative URL-resolution approach (see [ADR-0001](0001-php-8-4-floor.md)).
3. Something else decided in the moment.

**Option 2 was taken.** The converter now hand-ports RFC 3986 §5.2 reference resolution, the floor is 8.4, and the Playground harness runs on PHP 8.4 with no `Uri`-extension dependency — so the risk that prompted this ADR can no longer arise from URL resolution.

## Consequences

- An automated DDEV fallback must not be wired into tooling or CI. Playground failure surfaces as an explicit "raise this" signal.
- The PHP floor is owner-controllable, not externally fixed; after Option 2 it sits at 8.4 (the `Dom\HTMLDocument` requirement).
