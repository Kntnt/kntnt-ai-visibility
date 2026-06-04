# Coding Standards

This document defines the project's coding standard. The general rules
below apply to all code in the project. Language- and framework-specific
rules follow in their own sections (PHP, WordPress, TypeScript, plain
JavaScript). Only the sections that match the project's actual shape
are included.

## Priority order

When two rules conflict, the higher-priority rule wins:

1. **This document and its companion modules.** Together they are the
   project's coding standard.
2. **The recommended coding standard for the language** — PSR-12 for
   PHP, the WordPress Coding Standards for WordPress projects, the
   TypeScript handbook style, MDN's JavaScript style, etc.
3. **Best practice** — well-reasoned community advice (Airbnb JS,
   Clean Code, the WordPress Plugin Handbook, etc.).
4. **Widely accepted conventions** — what most code in the wild looks
   like.

## Design philosophy

These principles often conflict. The task is to find the design that
best honours all of them — not to apply each mechanically in sequence.
When in doubt, start with YAGNI and work down the list.

**YAGNI** — Implement only what the current requirement demands. Do
not create abstractions until more than one concrete implementation
exists.

**KISS** — Prefer the simpler solution. Complexity must justify itself
through a concrete, present requirement.

**DRY** — Each piece of knowledge has one authoritative source.
Extract duplication only when two things represent the same concept —
not merely similar syntax.

**TDD** — Write a failing test before writing production code. Follow
Red/Green/Refactor. Structure each test as Arrange-Act-Assert with a
name that states the expected behaviour.

**Deep modules** — A module's external interface must be narrow and
simple relative to the complexity it hides. This depth creates a clean
seam for mocking and is the primary quality metric for a module
boundary. The external interface is a commitment; design it as if it
cannot be changed.

**SOLID** applies inside a module — to the internal structure of classes
and components, not to the module's external interface:

- **SRP** — one reason to change per class.
- **OCP** — extend through new code, not by modifying existing code.
- **LSP** — subtypes must fully honour the base type's contract.
- **ISP** — internal components depend only on the interface slice they
  actually use. Decompose large internal interfaces into focused ones.
- **DIP** — depend on abstractions; inject dependencies.

**Boundary rule**: ISP decomposition is an internal detail and must
never surface in the module's external interface. The external
interface stays deep.

## Universal rules

### Language

- All identifiers (classes, interfaces, enums, traits, functions, methods,
  variables, constants, properties, type parameters, etc.) are in **English**.
- All comments — file-level, block-level, end-of-line, PHPDoc, JSDoc, TSDoc —
  are in **English**.
- All technical documentation (`README.md`, `CLAUDE.md`, `AGENTS.md`, files
  in `docs/`) is in **English**.
- User-facing strings are translatable and may be authored in any language;
  the source string in `__()` / `gettext()` calls is English.

### Versions and targets

- Use the latest stable major.minor of any chosen language — UNLESS
  an earlier version is required by the project you're working in or
  by a library/dependency the project depends on. Pin the constraint
  explicitly when it applies; don't drift below latest by accident.
- For browser-targeted code, target the most recent edition of
  ECMAScript supported by the current stable releases of Safari,
  Firefox, Chrome, and Edge. In practice this currently means **ES2022**;
  revisit the target as evergreen support for newer editions catches up.
- No polyfills, no transpiler-emitted runtime helpers for older
  targets.

This rule is stated once here and not restated in the per-language
modules.

### Code is read as prose

Code is read as prose. The reader is always a senior developer fluent
in the language and the framework. Loosely:

- A file is a chapter or short essay.
- A class or function is a section.
- A *paragraph* (Swedish *stycke*) — a group of consecutive statements
  that logically belong together — is the basic unit of structure
  inside a block, with a `//` comment as its topic sentence.
- A statement is a sentence.

This shapes how blocks are paragraphed and how comments are written.
The next section is the most central rule in this whole standard:
follow it carefully.

### Paragraphs and comments

**Paragraphing inside blocks.** Inside any block — a function body, a
loop body, an `if` / `else` branch, a `try` / `catch` branch — group
consecutive statements that logically belong together into a *paragraph*
(*stycke*). A paragraph has:

- No blank line between its statements.
- A single-line `//` comment above it that names what the paragraph
  does. The comment is a topic sentence, not an explanation; it lets
  the reader skim and skip.
- A blank line above the comment and a blank line below the last
  statement — even when the paragraph is the first or last thing in
  the enclosing block, so it sits flush against the opening `{` or
  the closing `}`.

A *trivial* paragraph — a lone `return $x;`, a single `global $wpdb;`,
a one-line assignment whose intent the surrounding code makes obvious —
may stand without a `//` comment. **The blank-line rule still applies,
though**: when the other paragraphs in the same block are separated by
blank lines, the trivial one is too. The first line after `{` must not
be jammed against the brace when other paragraphs breathe; a closing
`return` must not sit immediately above `}` either. Visual consistency
across the block matters.

```php
public function dispatch( string $token ): void {

    // Reject malformed tokens — defense-in-depth in case the upstream
    // validator is bypassed.
    if ( ! $this->validator->is_valid( $token ) ) {
        $this->send_error( 400 );
    }

    // Resolve the token to a target record; 404 when missing.
    $record = $this->repository->find_by_token( $token );
    if ( ! $record ) {
        $this->send_error( 404 );
    }

    // Forward incoming query parameters to the redirect target and dispatch.
    $params = array_map( 'sanitize_text_field', $_GET );
    $target = add_query_arg( $params, get_permalink( $record->id ) );
    wp_safe_redirect( $target );
    exit;

}
```

The example is in PHP but the rule is identical in TypeScript and
plain JavaScript.

**Single-paragraph block — the introducing comment absorbs the
explanation.** When a block consists of one paragraph that needs no
explanation of its own, drop both the `//` comment and the surrounding
blank lines, and make sure the comment that introduces the **enclosing
statement** carries everything a reader needs. For a function body that
introducing comment is the PHPDoc / JSDoc; for an `if` / `else` /
`while` / `for` / `try` body it is the `//` comment that sits above the
control statement.

```php
/**
 * Registers the custom query variable so WordPress preserves it through
 * the rewrite engine.
 */
public function add_query_var( array $vars ): array {
    $vars[] = 'my_query_var';
    return $vars;
}

// Refresh the access token only when the cache misses; a hit is the
// fast path.
if ( ! $cached_token ) {
    $token = $this->oauth->refresh();
    $this->cache->set( 'access_token', $token, 3500 );
}
```

**Doc comments.** Every file, class, interface, enum, trait, function,
method, public property, and exported constant carries a doc comment
(PHPDoc / JSDoc / TSDoc). Include the why, the contract, and edge cases —
not the what. Use `@param`, `@return`, `@throws`, `@since`, `@example`
where they add real value.

**End-of-line comments.** Use sparingly, only where a reader could plausibly
miss a subtle but critical detail (a magic constant chosen for a reason, a
non-obvious off-by-one, a workaround for a known platform bug).

**Audience.** All comments are written for an experienced developer reading
the file for the first time. Do not restate what the code already shows.
Do not write tutorials, do not address juniors, do not narrate the obvious.

**Line wrapping.** Comments wrap at column 80. Code may go wider where it
improves readability — see formatter settings per language below.

### Whitespace

- **No vertical alignment of `=` or `=>`.** Do not align assignment
  operators or array arrows across multiple lines. Single-space the
  operator and move on. The realignment churn on every edit is a real
  cost and the visual benefit is negligible for a senior reader.
- **No padding inside short collections.** Short array literals stay on one
  line: `[1, 2, 3]`, not split.
- **No gratuitous line breaks** in parameter lists. Pass parameters on one
  line unless the line genuinely becomes hard to read or exceeds the
  formatter's max line width.
- **Motivated line breaks are fine.** Break an array literal across lines
  when its elements naturally form a list or a matrix — for example, lookup
  tables, observer thresholds, route definitions, fixture rows. The
  break-or-not decision is content-driven, not character-count-driven.

```php
// Motivated: the elements form a fixed list.
$thresholds = [ 0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0 ];

// Motivated: the elements form a matrix.
$routes = [
    [ 'GET',  '/clicks',      'list_clicks' ],
    [ 'POST', '/conversions', 'record_conversion' ],
    [ 'GET',  '/health',      'health_check' ],
];

// Unmotivated: do not split a short call onto multiple lines.
$user = create_user( $name, $email, $role );
```

### Modern syntax

Always prefer the modern construction over the legacy one. Use syntactic
sugar where the language offers it: nullish coalescing, null-safe operator,
spread, destructuring, arrow functions, match/switch expressions, pattern
matching, template literals. Specific examples are in the language modules.

### Identifiers

- Names are self-documenting. Avoid abbreviations except well-established
  ones (`url`, `id`, `db`, `i` in tight loops).
- No magic strings or numbers in business logic — extract them to named
  constants or enum cases.
- Boolean variables and methods read as predicates: `isReady`, `hasConsent`,
  `should_retry()`.

### Naming and prefixes

Wherever there is a real risk of name collision in a global registry —
WordPress plugins and themes are the canonical case, but the same logic
applies to npm package names, browser globals, custom DOM events, and
similar — use a project prefix:

- **`kntnt-`** (with hyphens) where the surrounding convention requires
  hyphens: plugin/theme directory names, plugin slugs, text domains,
  REST namespaces, file paths, CSS class names, npm package names,
  custom HTML data attributes.
- **`kntnt_`** (with underscores) where the surrounding convention
  requires underscores: PHP function names, hook names, option keys,
  transient keys, post-type slugs, capability slugs, user-meta keys,
  JavaScript globals.

After the prefix comes the project's own name, then one or more words
describing the purpose:

```
kntnt-<project>                        ← plugin slug, repo name, dir name
kntnt_<project>_<purpose>              ← hook, option, post-type slug
kntnt-<project>-<purpose>              ← CSS class, REST endpoint segment
```

The project name itself does **not** start with `kntnt` — the prefix
provides that segment exactly once. A project called simply `<project>`
gets the slug `kntnt-<project>`, not `kntnt-kntnt-<project>`; its hooks
are `kntnt_<project>_<purpose>`, not `kntnt_kntnt_<project>_<purpose>`.

When the project name is long, an abbreviation may be used in
identifiers where length matters (hooks, option keys, post-type slugs).
The plugin's own `README.md` documents the abbreviation. Human-facing
places — the plugin name, the repository name, the documentation —
keep the full name.

PHP namespaces follow the same composition rule with their own casing.
The root is `\Kntnt`, then the project's name (without re-prefixing) in
`Pascal_Snake_Case`, then any sub-namespaces:

```
\Kntnt\<Project>                       ← root namespace for the project
\Kntnt\<Project>\<Sub>\<Class_Name>    ← organised further as needed
```

Never `\Kntnt\Kntnt_<Project>\…` — the `\Kntnt` segment already provides
the prefix.

**When the prefix is not needed.** The prefix exists to prevent
collisions in a global registry. Where there is no global registry —
inside a TypeScript package whose public API is a set of named
exports, inside a Laravel application's `App\` namespace, inside a
SvelteKit project's `$lib`, inside a standalone script whose
identifiers stay in the script's own scope, etc. — the package,
namespace, or file boundary already provides the isolation, and an
extra `kntnt` prefix is noise. Apply the prefix where collisions can
happen (WordPress hooks, npm package names published to a public
registry, browser globals, custom DOM events, custom HTML data
attributes); skip it where they cannot.

## Universal tooling

The tools below apply to every project regardless of language. Tools
specific to a language live in that language's module. Substitutions
are allowed when a project has specific constraints; in that case the
substitution is documented in the project's `README.md`.

### Version control, hosting, and CI

- **Git** for local version control.
- **GitHub** for the remote, issues, pull requests, releases, and code
  review.
- **GitHub Actions** for continuous integration.

## CLAUDE.md / AGENTS.md convention

The project root contains both `CLAUDE.md` and `AGENTS.md`.

- `CLAUDE.md` is the entry point for Claude Code. It uses `@`-imports
  to pull in `AGENTS.md` and the relevant files in `docs/`, including
  this file (`docs/coding-standards.md`).
- `AGENTS.md` is the universal AI-agent file. Other tools (Copilot,
  Cursor, Codex, etc.) read it directly.

Both files reference this document so that any AI agent working on
the codebase has the coding standard in context before writing code.

## PHP

This section covers PHP rules. It applies whenever the project contains
PHP code. WordPress projects additionally use the WordPress rules,
which extend and override parts of this section.

### Baseline (all PHP)

- `declare( strict_types = 1 );` at the top of every PHP file.
- Use the language's modern features fully; no back-compatibility
  shims for older language versions.

### Required modern features

- Typed properties.
- `readonly` properties (PHP 8.1) and `readonly class` declarations
  (PHP 8.2) wherever immutability after construction is meaningful.
- Constructor property promotion where it shortens the class.
- Union and intersection types: `string|false`, `Countable&Iterator`.
- Named arguments at call sites where they aid readability.
- `match` expressions instead of `switch` statements.
- Arrow functions (`fn() =>`) for short callbacks.
- `enum` for closed sets of values; backed enums when the values cross
  a boundary (DB, JSON, query string).
- Null-safe operator: `$user?->getProfile()?->getEmail()`.
- First-class callable syntax: `array_filter( $items, $this->is_valid(...) )`.
- `str_contains()`, `str_starts_with()`, `str_ends_with()` instead of
  `strpos` comparisons.
- Array spread: `[ ...$existing, $new ]`.

### Universal style rules (all PHP)

These apply regardless of project. They are language-level preferences,
not surface-style choices.

- `[ ... ]` for arrays. Never `array(...)`.
- Trailing commas in multi-line arrays, multi-line parameter lists, and
  multi-line argument lists.
- `?:` and `??` as appropriate; do not chain them into puzzles.
- **Conditions: natural order by default.** Yoda conditions are
  acceptable when they make the intent clearer for an experienced reader
  than the alternative — for example in idiomatic null-checks
  (`if ( null === $value )`) or where the test is fundamentally a
  boolean assertion rather than a comparison. Strict types remove the
  original safety motivation for Yoda, so the choice is purely about
  readability.
- Code may go up to the project's max line width (120 cols is a sensible
  default). Comments wrap at column 80.

### Surface style — PSR-12

PHP code follows PSR-12 for indentation, spacing, and identifier
casing. WordPress projects override this with the WordPress Coding
Standards — see the WordPress rules.

| Question | Convention |
|---|---|
| Indentation | 4 spaces |
| Inside `(` / `)` | Tight: `if ($x === null)`, `foo($a, $b)` |
| Variables / properties | `$camelCase` |
| Methods / functions | `camelCase` |
| Classes / interfaces / enums / traits | `PascalCase` (e.g. `UserRepository`) |
| Class constants | `SCREAMING_SNAKE_CASE` |
| Namespace segments | `PascalCase` |

### File layout

PSR-4 autoloading. The autoloader maps the namespace prefix to a source
directory and uses the class name verbatim as the filename:

```
\Kntnt\<Project>\UserRepository              →  src/UserRepository.php
\Kntnt\<Project>\Audit\Logger                →  src/Audit/Logger.php
```

One class, interface, enum, or trait per file. Filename equals the
symbol name, case-sensitive.

The conventional source directory is `src/`. WordPress plugins use
`classes/` instead — see the WordPress rules.

### Doc comments

Every file, class, trait, interface, enum, method, function, property,
and constant has a PHPDoc block. Include `@since` from the first
release. Document the why and the contract; the type system already
shows the shape.

```php
/**
 * Resolves an opaque token into the user it belongs to.
 *
 * Returns `null` for malformed tokens, expired tokens, or tokens that
 * point to a deleted user. Callers must distinguish "no such user"
 * from "no permission" themselves.
 *
 * @since 1.0.0
 *
 * @param string $token Opaque identifier from the authenticator.
 * @return User|null
 */
public function resolveUser( string $token ): ?User { … }
```

### PHP tooling

- **Composer** for dependency management and PSR-4 autoloading.
- **Pest** for unit and feature tests — expressive, modern, fast.
- **PHPStan** for static analysis. Aim for `--level max` on new code;
  raise legacy code incrementally. PHPStan catches a class of bugs
  that tests alone do not — typos in property names, wrong argument
  types, dead branches.
- **pcov** for PHP code coverage.
- **DDEV** for any PHP project that needs a local server (PHP,
  database, web server). DDEV's project-local configuration is checked
  in so the environment is reproducible.

WordPress-specific PHP tooling — Brain Monkey, Mockery, the
`szepeviktor/phpstan-wordpress` extension, WordPress Playground for
integration tests — is described under the WordPress rules.

## WordPress

This section extends the PHP rules with rules specific to WordPress
plugins and themes. It applies in addition to (and in places overrides)
the PHP rules.

### Surface style — WordPress flavour (overrides PSR-12)

WordPress code follows the WordPress Coding Standards rather than
PSR-12:

| Question | Convention |
|---|---|
| Indentation | Tabs (display as 4 cols) |
| Inside `(` / `)` | Padded: `if ( $x === null )`, `foo( $a, $b )` |
| Variables / properties | `$snake_case` |
| Methods / functions | `snake_case` |
| Classes / interfaces / enums / traits | `Pascal_Snake_Case` (e.g. `User_Repository`) |
| Class constants | `SCREAMING_SNAKE_CASE` |
| Namespace segments | `Pascal_Snake_Case` |

The `Pascal_Snake_Case` class form (`User_Repository`) is the WordPress
flavour: the underscore-flavoured readability of WordPress identifiers
combined with a valid PSR-4 class name. The corresponding file is
`classes/User_Repository.php` — exact match, case-sensitive.

### Deliberate deviations from WP-CS — do not "fix" these

WordPress conventions otherwise apply, but the following four points
deliberately depart from WP-CS because the alternative is clearly
superior. They are not oversights. Do not "correct" them toward
upstream WP-CS in code reviews, refactors, or new files:

- **`[ ... ]` over `array(...)`** — modern PHP.
- **PSR-4 filenames over `class-classname.php`** — the autoloader maps
  `User_Repository` to `User_Repository.php`, not `class-user-repository.php`.
- **Namespaces over global function prefixes** — PHP code lives inside
  `\Kntnt\<Project>` rather than under a `kntnt_` function-name prefix.
  The prefix is still used for identifiers that live in a global
  registry — see *Naming and prefixes* in the universal rules.
- **Yoda is not required** — natural order by default, Yoda only when
  it genuinely improves readability (see the PHP rules).

### File layout in WordPress projects

WordPress plugins use `classes/` rather than `src/` as the PSR-4 source
directory:

```
\Kntnt\<Project>\Click_Handler              →  classes/Click_Handler.php
\Kntnt\<Project>\Conversion\Reporter        →  classes/Conversion/Reporter.php
```

Otherwise the PSR-4 rules from the PHP section apply.

### Security and i18n

- All SQL via `$wpdb->prepare()`. No raw interpolation.
- All admin URLs via `admin_url()` / `wp_nonce_url()`.
- Sanitise every superglobal access. No bare `$_GET['foo']`.
- All user-facing strings translatable: `__()`, `_e()`, `esc_html__()`,
  `esc_attr_e()` with the correct text domain.
- Output is escaped at the point of output: `esc_html`, `esc_attr`,
  `esc_url`, `wp_kses_post`.
- Errors are silent toward visitors. Diagnostics go to a plugin-managed
  log file or `error_log()`.
- Capabilities, not roles, gate admin actions.

### WordPress plugin project structure

```
kntnt-<name>/
├── kntnt-<name>.php          ← Main plugin file: header, PHP version
│                                guard, autoloader, Plugin::get_instance()
├── autoloader.php            ← PSR-4 autoloader for the plugin namespace
├── install.php               ← Activation: capabilities, migrator, cron,
│                                rewrite flush. Not autoloaded.
├── uninstall.php             ← Complete data removal. Runs without
│                                autoloader; uses fully qualified calls.
├── README.md                 ← Human-facing documentation
├── CLAUDE.md                 ← AI agent guidance (imports AGENTS.md,
│                                docs/*.md as needed)
├── AGENTS.md                 ← Universal AI agent instructions
├── classes/                  ← PSR-4: <Class_Name>.php
│   ├── Plugin.php            ← Singleton, component wiring, hooks
│   ├── Migrator.php
│   ├── Settings.php
│   ├── Logger.php
│   └── …
├── migrations/               ← Version-based migrations: <X.Y.Z>.php,
│                                each returns function(\wpdb): void
├── js/                       ← Plain ES2022 scripts, no build
├── css/
├── languages/                ← .pot, .po, generated .mo
├── docs/                     ← Specs the AI and humans both read
│   ├── architecture.md
│   ├── coding-standards.md   ← Project-specific overrides (rare)
│   ├── file-structure.md
│   ├── security.md
│   ├── testing-strategy.md
│   └── …
└── tests/
    ├── Unit/                 ← Pest + Brain Monkey + Mockery
    ├── JS/                   ← Vitest + happy-dom (or jsdom)
    └── Integration/          ← Bash + WordPress Playground / DDEV
```

The bootstrap path is fixed: `kntnt-<name>.php` → guard PHP version →
require `autoloader.php` → register activation/deactivation hooks →
call `Plugin::get_instance()`. The `Plugin` constructor instantiates
all components in dependency order and registers their WordPress hooks.

### WordPress-specific tooling

These tools complement the general PHP tooling.

- **Brain Monkey** + **Mockery** for mocking WordPress functions and
  collaborator dependencies in unit tests.
- **`szepeviktor/phpstan-wordpress`** as the PHPStan extension that
  teaches static analysis about WordPress core.
- **WordPress Playground** (WASM PHP + SQLite) for end-to-end
  integration tests. The whole stack spins up in 1–2 seconds without a
  server, which keeps CI fast and the local feedback loop tight.
  Playground is the default. Use it whenever it suffices — which is
  the great majority of cases.

  Only fall back to **DDEV-based** integration tests when Playground
  genuinely cannot exercise the behaviour under test: MySQL-specific
  SQL, database-level concurrency, transaction or locking semantics,
  missing PHP extensions, or multi-process scenarios such as cron jobs
  and queue workers. DDEV-based tests are the exception, are scoped
  narrowly to the case that requires them, and stay out of the fast
  PR-time test suite. Run Playground from the command line via
  `@wp-playground/cli`.

## Bash

This section covers Bash rules. It applies whenever the project
contains Bash code — typically standalone scripts and short
orchestration glue.

### Baseline

- GNU Bash 5+, installed from a current source (Homebrew on macOS).
  Not POSIX `sh`, and explicitly not Apple's frozen `/bin/bash` 3.2.
- Safety preamble at the top of every script: `set -euo pipefail`.
- When the script carries a shebang, it is `#!/usr/bin/env bash`.
  Never `#!/bin/bash`.

### Style

- Quote every expansion: `"$var"`, `"${arr[@]}"`. Unquoted
  expansions are bugs waiting to happen.
- `[[ ... ]]` for conditionals, never `[ ... ]`.
- `$( ... )` for command substitution, never backticks.
- Prefer builtins and parameter expansion over spawning external
  processes — `${var##*/}` over `basename "$var"`, `${var%.*}` over
  trimming with `sed`.

### Structure

- Decompose into functions. Top-level code is the script's entry
  point; the rest is named functions.
- `local` for every function-scoped variable. Globals are a smell.
- Arrays and associative arrays for collections, never
  space-delimited strings.
- `trap ... EXIT` for cleanup of temp files and child processes.
- Meaningful, intentional exit codes. `0` for success, distinct
  non-zero codes for documented failure modes.

### Doc comments

A leading `#` comment block at the top of the script describes what
it does, its arguments, its exit codes, and any required environment
or dependencies. Function-level `#` blocks for non-trivial functions.

### When Bash is the wrong tool

Bash is for short, pure orchestration. Escalate to another language
as soon as the script needs real data structures beyond what `jq`
can express, persistent state, unit tests, or anything that would
warrant more than a single shellcheck-clean file. Grow into another
language rather than growing the Bash script.

### Bash tooling

- **shellcheck** — every script must pass `shellcheck` without
  suppressions. Suppress only with an inline comment that names the
  rule and explains why.
- **shfmt** for formatting.
