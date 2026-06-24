# Spec 4 — Release 4: Content Signals (robots.txt AI-usage directives)

This is the Release-4 specification for the **Content Signals module**: the site-wide AI-usage declaration emitted as a `Content-Signal` directive in `robots.txt`. It turns the architectural seams of [`docs/architecture.md`](../architecture.md) and the decisions in [ADR-0012](../adr/0012-content-signals-tri-state-defaults.md) into concrete contracts an implementer can TDD against, mirroring the shape and rigour of the Release-2 spec [`docs/spec/llms-txt.md`](llms-txt.md) and the Release-3 draft [`docs/spec/link-headers.md`](link-headers.md). Where this spec states *what*, [ADR-0012](../adr/0012-content-signals-tri-state-defaults.md) states *why*; read the ADR when the reasoning matters.

Unlike the Release-3 spec, this one opens with **no unresolved fork**. The design decisions were settled in a dedicated design session and are recorded as settled in §1 and in ADR-0012. The implementer builds to them, not between them.

## Scope and shape

The module declares the site's preferences for how its content may be used by AI, using the Content Signals Policy vocabulary — `search`, `ai-input`, `ai-train` — emitted once, site-wide, under `User-agent: *` in `robots.txt`. It is a **declaration of usage preference, not a crawler blocklist** and not a named-AI-bot deny list.

A `Content-Signal` is a **group-level** directive with no per-path or per-type axis. The module therefore **cannot** mirror the content-type matrix and is a single site-wide policy. It is the plugin's simplest module:

- it registers **no** capability column (there is no per-type axis);
- it registers **no** artifact provider, **no** serve pattern and uses **no** cache or early router — a content signal is a directive injected into a file WordPress already owns, not a discoverable artifact ([`CONTEXT.md`](../CONTEXT.md));
- its `boot()` registers exactly two things: a **settings section** and the **`robots_txt` decorator**.

The module namespace is `Kntnt\Ai_Visibility\Signals`, mirroring the terse module namespaces of the other modules (`Markdown`, `Llms`, `Links`).

## 1. Settled decisions

1. **Declaration only.** The module emits the Content Signals Policy vocabulary. It does **not** emit a named-AI-bot blocklist (`User-agent: GPTBot` … `Disallow: /`) — that is a churning-maintenance game against zero-config and dependency-free, and a different product (ADR-0012, Charter §Market).
2. **Site-wide, tri-state.** One policy under `User-agent: *`; each signal is *grant* (`yes`) / *reserve* (`no`) / *defer* (omitted). No per-type or per-path control (the medium cannot express it).
3. **Defaults: `search`=defer, `ai-input`=grant, `ai-train`=defer.** The zero-config output is the single line `Content-Signal: ai-input=yes`. Rationale in ADR-0012.
4. **No feature toggle.** The module is always booted; the *defer* state is how a signal is "off". No per-module on/off (ADR-0006).
5. **Warnings on visibility-reducing choices.** Server-side (no JS): always-on help text plus post-save admin notices. See §6.
6. **Suppressed when `blog_public = 0`.** When the site globally discourages crawlers, the module emits nothing. See §3.4.
7. **Physical `/robots.txt` is a documented silent limitation.** The `robots_txt` filter fires only for WordPress's *virtual* `robots.txt`; a physical file in the web root bypasses it and the module. Documented, not worked around (§3.5).
8. **No `robots.txt` reference to `llms.txt`.** The deferred [`llms-txt.md`](llms-txt.md) §10 item is declined (ADR-0012, Consequences).

## 2. Domain model — `Signal_State` and `Policy`

The three signals and their three states are the module's whole domain. Two value objects carry them.

```php
namespace Kntnt\Ai_Visibility\Signals;

/**
 * One signal's declared state.
 *
 * The string backing is the stored/filtered form; directive_value() maps it to
 * the robots.txt token, returning null for Defer (the signal is then omitted).
 *
 * @since 0.4.0
 */
enum Signal_State: string {

    case Grant   = 'grant';
    case Reserve = 'reserve';
    case Defer   = 'defer';

    /**
     * The robots.txt directive value, or null when the signal is omitted.
     *
     * @since 0.4.0
     *
     * @return string|null 'yes' for Grant, 'no' for Reserve, null for Defer.
     */
    public function directive_value(): ?string;

}
```

```php
namespace Kntnt\Ai_Visibility\Signals;

/**
 * The resolved, site-wide content-signal policy.
 *
 * @since 0.4.0
 */
final readonly class Policy {

    public function __construct(
        public Signal_State $search,
        public Signal_State $ai_input,
        public Signal_State $ai_train,
    ) {}

    /**
     * The signals to emit, in canonical order, as [ directive-name => 'yes'|'no' ].
     *
     * Deferred signals are omitted, so an all-defer policy returns []. The
     * directive names are hyphenated ('ai-input', 'ai-train'); the option keys
     * are underscored ('ai_input', 'ai_train').
     *
     * @since 0.4.0
     *
     * @return array<string, string> e.g. [ 'ai-input' => 'yes' ].
     */
    public function directives(): array;

    /**
     * The zero-config default policy: search=defer, ai-input=grant, ai-train=defer.
     *
     * @since 0.4.0
     *
     * @return self
     */
    public static function default(): self;

}
```

The canonical signal order for `directives()` is `search`, `ai-input`, `ai-train` (the policy's own listing order), so the emitted line is stable and diff-friendly.

## 3. The emitted directive

### 3.1 Format

The module contributes one comment line and one `Content-Signal` line into the `User-agent: *` group:

```
User-agent: *
# Kntnt AI Visibility — AI usage content signals
Content-Signal: ai-input=yes
```

The `Content-Signal` value is the comma-and-space-joined list of `name=value` pairs from `Policy::directives()`, in canonical order. When `directives()` is empty (every signal deferred), the module contributes **nothing** — no comment, no directive line.

### 3.2 The default output

With the zero-config defaults (search=defer, ai-input=grant, ai-train=defer), `directives()` is `[ 'ai-input' => 'yes' ]`, so a fresh install emits exactly:

```
Content-Signal: ai-input=yes
```

This says one thing — *yes, AI may use this content to answer questions* — and leaves search to the site's other indexing controls and training to law and practice.

### 3.3 Injection point

The directive is a **group member record**, so it must sit inside the `User-agent: *` group. The decorator **splices** it into WordPress's existing `*` group rather than appending a second `User-agent: *` group (duplicate groups are parsed inconsistently across crawlers; Cloudflare's reference implementation splices into the existing group):

- locate the `User-agent: *` line in the filtered output (case-insensitive on the agent token, matching WP's literal `User-agent: *`);
- insert the comment line and the `Content-Signal` line immediately after it;
- **fallback:** if no `*` group is present (unusual — another plugin has rewritten the output), append a fresh `User-agent: *` block carrying only the comment and the directive.

The decorator never reorders, removes or rewrites any existing line; it only inserts its two lines.

### 3.4 Suppression on a non-public site

When `blog_public = 0` (Settings → Reading → "Discourage search engines…"), WordPress already emits `Disallow: /`. The `robots_txt` filter passes the resolved public flag as its second argument; when it is false the decorator returns the output **unchanged**. The owner has globally opted out of discoverability, and the module defers to that — it does not emit an `ai-input=yes` that contradicts a site-wide `Disallow`.

### 3.5 Physical `robots.txt` (documented limitation)

The `robots_txt` filter runs only when WordPress generates a **virtual** `robots.txt`. If a physical `robots.txt` file exists in the web root, the web server serves it directly, WordPress never runs, and the module emits nothing — silently. This cannot be worked around without the plugin writing to the web root, which it will not do. The README and the settings help text state the limitation; on a site with a static `robots.txt`, the content signals must be added to that file by hand.

## 4. Contracts

### 4.1 `Signals\Module`

```php
namespace Kntnt\Ai_Visibility\Signals;

use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Module as Module_Contract;

/**
 * Release-4 module: declares AI-usage content signals in robots.txt.
 *
 * boot() registers the module's settings section with the Core settings
 * registry and the robots.txt decorator. It registers no provider, no serve
 * pattern, no capability column and no invalidation — the module reads three
 * settings and decorates robots.txt, nothing more.
 *
 * @since 0.4.0
 */
final class Module implements Module_Contract {

    public function boot( Core $core ): void;

}
```

`boot()` builds a `Settings` (bound to the option key), registers its section via `$core->settings()->register_section( $settings->section() )` and its post-save notices, then builds a `Robots_Decorator` with a policy resolver (`$settings->policy(...)`) and a public-flag reader and calls `$decorator->register()`. The module is wired into `Plugin` after the `Llms\Module` boot line; it depends on no other module.

### 4.2 `Signals\Robots_Decorator`

```php
namespace Kntnt\Ai_Visibility\Signals;

/**
 * Splices the Content-Signal directive into the virtual robots.txt.
 *
 * @since 0.4.0
 */
final class Robots_Decorator {

    /**
     * @param \Closure(): Policy $policy    Resolves the effective policy (saved → default → filter).
     * @param \Closure(): bool   $is_public Reads the site's public flag (get_option('blog_public')).
     */
    public function __construct(
        private \Closure $policy,
        private \Closure $is_public,
    ) {}

    /**
     * Registers the robots_txt filter.
     *
     * @since 0.4.0
     *
     * @return void
     */
    public function register(): void;

    /**
     * Decorates the robots.txt output with the content-signal block.
     *
     * Returns the output unchanged when the site is non-public or when the
     * resolved policy defers every signal. Otherwise splices the directive into
     * the `User-agent: *` group (§3.3).
     *
     * @since 0.4.0
     *
     * @param string $output The robots.txt content so far.
     * @param bool   $public WordPress's public flag for the request.
     * @return string The decorated output.
     */
    public function decorate( string $output, bool $public ): string;

}
```

`register()` hooks `add_filter( 'robots_txt', [ $this, 'decorate' ], 10, 2 )`. The decorator trusts the `$public` argument WordPress passes; the injected `$is_public` reader is for unit tests and for the rare path where the policy needs the flag outside the filter.

### 4.3 `Signals\Settings`

```php
namespace Kntnt\Ai_Visibility\Signals;

use Kntnt\Ai_Visibility\Core\Settings\Section;

/**
 * The module's settings section, resolver, sanitiser and warnings.
 *
 * @since 0.4.0
 */
final class Settings {

    /** The section id; namespaces the slice within the single option. */
    public const SECTION_ID = 'content_signals';

    /** The developer override filter applied to the resolved policy. */
    public const FILTER = 'kntnt_ai_visibility_content_signals';

    public function __construct(
        private readonly string $option_key = 'kntnt_ai_visibility',
    ) {}

    /**
     * Builds the custom "AI usage" settings section (three tri-state controls + help).
     *
     * @since 0.4.0
     *
     * @return Section
     */
    public function section(): Section;

    /**
     * Resolves the effective policy: saved value, else default, then the developer filter.
     *
     * @since 0.4.0
     *
     * @return Policy
     */
    public function policy(): Policy;

    /**
     * Sanitises submitted input into a clean [ signal => state-string ] slice.
     *
     * Walks the three known signal keys only; an unknown key cannot leak into the
     * stored option. Each value is coerced via Signal_State::tryFrom(), falling
     * back to the signal's zero-config default when missing or invalid. Registers
     * the applicable warnings (§6) via add_settings_error() on the way through.
     *
     * @since 0.4.0
     *
     * @param mixed $input The raw submitted slice.
     * @return array<string, string> The cleaned [ 'search'|'ai_input'|'ai_train' => state ] slice.
     */
    public function sanitize( mixed $input ): array;

}
```

`policy()` reads the `content_signals` slice of the `kntnt_ai_visibility` option, fills each missing/invalid signal from `Policy::default()`, builds a `Policy`, then applies the developer filter (§5.2) and revalidates the result through `Signal_State::tryFrom()` so a bad filter return cannot produce an invalid policy.

## 5. Settings and the developer filter

### 5.1 The "AI usage" section

A custom section (the same shape as Core's `Content_Settings`, not field-by-field), registered by the module with the Core settings registry (ADR-0010, "a section per module"; this is the first **module-owned** section — Core owns the content-type section). It renders, server-side and JS-free:

- a short description of what content signals are and that they are advisory;
- three labelled `<select>` controls — **Search**, **AI input**, **AI training** — each offering *Allow* (`grant`), *Disallow* (`reserve`) and *Defer to law and practice* (`defer`), pre-selected to the effective value;
- per-control help text (§6) explaining the consequence of each choice.

The control names are `kntnt_ai_visibility[content_signals][search|ai_input|ai_train]`; the section's `sanitize` closure delegates to `Settings::sanitize()`.

### 5.2 The developer filter

`policy()` applies one filter, mirroring the per-capability escape hatch the other modules expose:

```php
$states = apply_filters(
    'kntnt_ai_visibility_content_signals',
    [ 'search' => 'defer', 'ai_input' => 'grant', 'ai_train' => 'reserve' ], // the resolved string states
);
```

The filter receives and returns the three string-backed states keyed by signal; the module revalidates each through `Signal_State::tryFrom()` before building the `Policy`, so an unknown or malformed value falls back to that signal's default rather than corrupting the output.

## 6. Warnings

Warnings are server-side only (the settings page carries no JS, ADR-0010): always-visible help text under each control, plus post-save admin notices raised from `Settings::sanitize()` through `add_settings_error()` and rendered on the settings screen after the save redirect.

| Saved value | Notice level | Message (sense) |
|---|---|---|
| `search` = reserve | error | Reserving search may remove you from search results, and the signal may be ignored; use `noindex` (your SEO plugin or Settings → Reading) instead — it is the stronger, conventional control. |
| `search` = grant | info | This may conflict with a `noindex` set elsewhere (your SEO plugin or WordPress); that control wins, so this signal may have no effect. |
| `ai-input` = reserve | warning | This plugin exists to help AI find and represent your content; disabling AI input works against that purpose. |
| `ai-train` = any | — | No notice. The help text explains that *Defer* leaves training to prevailing law and practice (in many jurisdictions, effectively tacit permission), *Allow* grants it explicitly and *Disallow* reserves it explicitly. |

The help text and notices describe **what each choice does**; they do not give legal advice. Any jurisdiction-specific colour (e.g. the EU text-and-data-mining reservation) lives in the README, not in the UI strings.

## 7. Interaction with other modules and the early router

None. The module reads no artifact registry, writes no cache, and is never reached by the early serve router (`robots.txt` is a normal WordPress request, not a cache-grade artifact). It depends on no other module and is depended on by none. It is wired last in `Plugin` purely for readability; boot order carries no dependency.

The `Discovery_Context` change Release 3 makes (ADR-0012's sibling, [`link-headers.md`](link-headers.md)) does not touch this module: content signals are not advertised and have no provider.

## 8. Testing strategy

### 8.1 Unit tests (Pest + Brain Monkey)

- **`Signal_State::directive_value()`** — Grant → `'yes'`, Reserve → `'no'`, Defer → `null`.
- **`Policy::directives()`** — default policy → `[ 'ai-input' => 'yes' ]`; all-grant → all three in canonical order; all-defer → `[]`; a mixed policy preserves canonical order.
- **`Policy::default()`** — search=Defer, ai_input=Grant, ai_train=Defer.
- **`Settings::sanitize()`** — coerces each known signal via `tryFrom`; a missing signal falls back to its default; an invalid string falls back to its default; an **injected unknown key is dropped**; the applicable `add_settings_error()` notices are registered for `search=reserve`, `search=grant`, `ai_input=reserve`.
- **`Settings::policy()`** — saved value overrides default; the developer filter overrides the saved value; a malformed filter return is revalidated to the default.
- **`Robots_Decorator::decorate()`** — non-public site (`$public = false`) → output returned unchanged; all-defer policy → output unchanged (no block); default policy → the `Content-Signal: ai-input=yes` line spliced **inside** the `User-agent: *` group, immediately after the agent line; a mixed policy → the canonical comma-joined value; missing `*` group → the fallback block appended; existing lines are never reordered or removed.
- **`Signals\Module::boot()`** — registers the section with the settings registry and the `robots_txt` filter; registers no provider, serve pattern or column.

### 8.2 Playground e2e

End-to-end assertions run on WordPress Playground with no DDEV fallback (ADR-0004), driving real HTTP against the live `robots.txt`.

- `GET /robots.txt` on a fresh install contains `Content-Signal: ai-input=yes` inside the `User-agent: *` group, and contains **no** `search=` or `ai-train=` token.
- After setting (through a test-only settings endpoint added to the e2e mu-plugin) `search=reserve` and `ai-train=reserve`, `GET /robots.txt` shows `Content-Signal: search=no, ai-input=yes, ai-train=no`.
- After setting every signal to *defer*, `GET /robots.txt` contains **no** `Content-Signal` line and no Kntnt comment.
- With `blog_public = 0`, `GET /robots.txt` contains `Disallow: /` and **no** `Content-Signal` line.
- The decorator never breaks the file: the WordPress default `User-agent: *` / `Disallow:` / `Allow:` / `Sitemap:` lines remain present and unreordered.

The **physical-`robots.txt` limitation cannot be exercised in Playground** (php-wasm serves the virtual file; placing a static file in the web root to shadow it is outside the harness). Per ADR-0004 this is not a reason to add DDEV — the limitation is asserted by the unit-tested filter contract (the decorator only ever runs when the filter fires) and documented; if a future need to exercise it end-to-end arises, STOP and raise it to the maintainer.

## 9. Build order (test-first)

1. **`Signal_State` + `Policy`** — the enum and the value object with `directives()` / `default()`; unit tests for every state and order.
2. **`Settings`** — the resolver, sanitiser (including injected-key rejection and the warning notices) and the section; unit tests, then the developer filter.
3. **`Robots_Decorator`** — `decorate()` for every branch (non-public, all-defer, default, mixed, missing-group fallback, line-preservation); unit tests first.
4. **`Signals\Module` + `Plugin` wiring** — boot the module after `Llms\Module`.
5. **Playground e2e** — all assertions in §8.2.

## 10. Out of scope for Release 4

- Named-AI-bot blocklists (`User-agent: GPTBot` … `Disallow`): a different product, against zero-config and dependency-free (§1.1, ADR-0012).
- Per-type or per-path content signals: the `Content-Signal` directive has no such axis (§Scope).
- A `robots.txt` reference to `llms.txt`: declined (ADR-0012; the deferred `llms-txt.md` §10 item is resolved here).
- A feature on/off toggle, for this module or any other: ADR-0006, ADR-0012.
- Writing to a physical `robots.txt`: the plugin will not write to the web root (§3.5).
- Reading or integrating with any SEO plugin's settings: the `search` deferral is passive; dependency-free holds (ADR-0012).
- A content-type matrix column or capability for content signals: the policy is site-wide and has no per-type axis.
- Any caching, serve pattern, rewrite rule or early-router involvement: `robots.txt` is a normal WordPress request (§7).
