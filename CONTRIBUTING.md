# Contributing to Kntnt AI Visibility

Thank you for considering a contribution. This plugin aims to make content-rich websites discoverable, visible and readable by AI agents with zero configuration, and contributions of every size help – from a typo fix to a new feature module.

## Ways to contribute

- **Report a bug or request a feature.** [Open an issue](https://github.com/Kntnt/kntnt-ai-visibility/issues), and search the existing issues first to avoid duplicates.
- **Ask a question or float an idea.** Use [Discussions](https://github.com/Kntnt/kntnt-ai-visibility/discussions) rather than the issue tracker.
- **Submit a pull request.** Fix a bug, improve the documentation, add a translation or implement a feature.

For anything larger than a small fix, open an issue or a discussion first so the approach can be agreed before you invest the work.

## Development setup

```bash
git clone https://github.com/Kntnt/kntnt-ai-visibility.git
cd kntnt-ai-visibility
composer install
```

The plugin requires **PHP 8.5** – the floor comes from the bundled `kntnt/html-to-markdown` converter (see [`docs/adr/0001-php-8-5-floor.md`](docs/adr/0001-php-8-5-floor.md)). The end-to-end tests additionally need Node.js for the WordPress Playground harness.

## Quality gates

Every change must pass the same gates CI enforces. Run them locally before opening a pull request:

```bash
composer phpcs     # WordPress Coding Standards (with the documented deviations)
composer stan      # PHPStan at level max
composer test      # Pest unit suite
bash run-tests.sh  # Level 1 (Pest) + Level 2 (Playground e2e)
```

`composer phpcbf` fixes most coding-standard violations automatically. The Playground e2e layer boots the plugin on PHP 8.5 via `@wp-playground/cli`; there is deliberately **no** automatic DDEV fallback (see [`docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md`](docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md)). If Playground genuinely cannot exercise a behaviour, raise it on the issue tracker rather than wiring in a fallback.

## Coding and writing standards

- **Code** follows the project coding standard in [`agents.d/coding-standard/`](agents.d/coding-standard/) (general, PHP, WordPress and Bash). Note the four deliberate deviations from the WordPress Coding Standards – `[ ]` arrays, PSR-4 filenames, namespaces over global function prefixes and no required Yoda conditions – which are enforced in `phpcs.xml.dist` and must not be "corrected" toward upstream WP-CS.
- **Naming** follows the conventions in [`AGENTS.md`](AGENTS.md): namespace `Kntnt\Ai_Visibility`, slug and text domain `kntnt-ai-visibility` and the `kntnt_ai_visibility_` prefix for options, transients and hooks.
- **Documentation** is written in British English following the `kntnt-text-skills:writing-rules en_GB` standard – spaced en-dashes ( – ), `-ise`/`-isation` spellings and no Oxford comma.

## Pre-1.0 policy

While the major version is `0`, the project makes **no backwards-compatibility commitments**. There are no installations in the wild, so pick the cleanest end state and ship the breaking change rather than carrying migrations or deprecations. This policy sunsets automatically when the version crosses `1.0.0`.

## Pull-request process

1. Branch from `main` and keep each pull request focused on a single concern.
2. Make sure the quality gates above pass locally.
3. Open the pull request against `main`. CI runs lint, static analysis, unit (coverage ≥ 80 %) and e2e jobs on PHP 8.5; all must be green.
4. Describe what changed and why. Link any related issue.

## Licence

By contributing, you agree that your contributions are licensed under the [GPL-2.0-or-later](LICENSE) licence that covers the project.
