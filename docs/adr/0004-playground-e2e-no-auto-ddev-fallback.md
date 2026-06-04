# e2e tests run on WordPress Playground; no automatic DDEV fallback

End-to-end tests use **WordPress Playground** (WASM PHP, `@wp-playground/cli`), wired as a second level in `run-tests.sh` and `tests/Integration/`. Playground's latest supported PHP is 8.5, so it can boot the plugin. This satisfies Charter step 1.4 ("unit and e2e tests") and follows the `coder` standard's "Playground default" rule.

## The escalation rule (deviation from the standard)

The `coder` standard says to fall back to DDEV when Playground can't exercise a behaviour (e.g. a missing PHP extension). We deliberately **do not** auto-fall-back here. The known risk is that php-wasm's 8.5 build may not bundle the `Uri\Rfc3986\Uri` extension the converter uses for relative-URL resolution. If that — or anything else — makes Playground unviable, the question is **lifted to the maintainer for a decision**, not resolved automatically.

The decision space at that fork is at least three-way, because Kntnt **owns** the converter:

1. Use DDEV for the integration layer (the standard's default fallback).
2. Lower the converter — and thus the plugin — to **PHP 8.4**, replacing the `Uri\Rfc3986\Uri` usage with an alternative URL-resolution approach (see [ADR-0001](0001-php-8-5-floor.md)).
3. Something else decided in the moment.

## Consequences

- An automated DDEV fallback must not be wired into tooling or CI. Playground failure surfaces as an explicit "raise this" signal.
- Option 2 means the PHP floor is not truly fixed at 8.5; it is owner-controllable.
