Read when running or changing the test suites.

`bash run-tests.sh [--unit-only|--e2e-only]` runs Level 1 (Pest unit) then Level 2 (WordPress Playground e2e, `@wp-playground/cli`, PHP 8.4).

The Playground harness lives in `tests/Integration/` (`blueprint.json`, `assert-boot.php`, `playground-smoke.sh`). The Pest `Integration` suite runs it only when `KNTNT_RUN_PLAYGROUND=1`, so a plain `pest` run stays offline.

No DDEV fallback – if Playground cannot exercise a behaviour, STOP and raise it (see Ground rules and ADR-0004). CI runs lint, stan, unit (coverage ≥ 80 %) and e2e on PHP 8.4.
