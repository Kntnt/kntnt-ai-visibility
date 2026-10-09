Read when running or changing the test suites.

`bash run-tests.sh [--unit-only|--e2e-only]` runs Level 1 (Pest unit) then Level 2 (WordPress Playground e2e, `@wp-playground/cli`, PHP 8.4).

The Playground harness lives in `tests/Integration/` (`blueprint.json`, `assert-boot.php`, `playground-smoke.sh`). The Pest `Integration` suite runs it only when `KNTNT_RUN_PLAYGROUND=1`, so a plain `pest` run stays offline.

For changes to Accept negotiation or integration hooks, inspect the targeted regressions alongside the complete suite:

- `tests/Unit/Markdown/Request_HandlerTest.php` covers media-range preferences, quality weights and routing precedence.
- `tests/Unit/Markdown/Negotiated_Cache_PolicyTest.php` covers canonical HTML's `Vary` policy and bypass exclusions for HTML and explicit alternates.
- `tests/Integration/playground-negotiated-cache.py` verifies negotiated HTTP responses and HTML isolation.
- `tests/Integration/playground-public-rendering.py` verifies anonymous rendering, publication vetoes and caller-state restoration.
- `tests/Integration/playground-public-content.py` verifies the public-body filter across alternate and aggregate consumers, including failures and recovery.
- `tests/Integration/playground-rendered-meta.py` and `tests/Integration/playground-indirect.py` verify declared metadata and committed indirect dependency invalidation.

Optional site adapters own their vendor and theme tests in the site plugin's repository. Passing Core's generic-hook tests does not verify a production cache that serves before WordPress; use `docs/operations/negotiated-cache.md` for deployment checks.

No DDEV fallback – if Playground cannot exercise a behaviour, STOP and raise it (see Ground rules and ADR-0004). CI runs lint, stan, unit (coverage ≥ 80 %) and e2e on PHP 8.4.
