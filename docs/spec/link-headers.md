# Spec 3 — Release 3: RFC 8288 Link-header discovery (Link headers module)

> **Ratified 2026-06-23.** The four open decisions (§1 and the closing "Decisions" section) are now settled — see that section for the outcomes. In particular, **`Discovery_Context` gains a nullable post and NO `$scope` field**: the `string $scope` shown in §1a, §2.3 and §4 below is superseded (a null post *is* the site-wide call; providers branch on `post === null`). The authoritative implementation contract is `plans/013-build-link-headers-module.md`; where this draft's code blocks differ, follow the plan.

This is the Release-3 specification for the **Link headers module**: RFC 8288 HTTP `Link` headers advertising every registered discoverable artifact on each HTML response. It turns the architectural seams of [`docs/architecture.md`](../architecture.md) and ADR-0006 … 0010 into concrete contracts an implementer can TDD against, mirroring the shape and rigour of the Release-2 spec [`docs/spec/llms-txt.md`](llms-txt.md). Where this spec states *what*, the linked ADR states *why*; read the ADR when the reasoning matters.

Scope of this spec: the **architectural fork that must be resolved before implementation can begin** (the discovery-context problem, §1), then the **Core seam change it requires**, the **`Links\Module` contracts**, the **provider advertising table**, the **interaction with the early router**, the **testing strategy** and the **build order**. The fork is presented with concrete options and recommendations; the final choice belongs to the maintainer.

## The central architectural fork

`Discovery_Context` (`classes/Core/Artifact/Discovery_Context.php`) is today a `final readonly class` that wraps a single non-nullable `\WP_Post $post`. Every provider's `advertise( Discovery_Context $context )` call therefore presupposes a concrete page being rendered. That assumption holds for per-page artifacts (Markdown alternates) but breaks for the llms.txt singletons (`/llms.txt`, `/llms-full.txt`), which are site-wide and have no associated `WP_Post`. When the Link headers module walks the registry on an HTML response, it must be able to ask each provider "what do you advertise globally?" — yet the existing context type carries no mechanism for that question. This is the single fork the maintainer must resolve before Release 3 can be built; §1 presents the options.

## 1. Decisions (open — for the maintainer)

### 1a. Discovery context for site-wide artifacts

The `Provider::advertise( Discovery_Context $context )` interface is a committed seam (ADR-0006). The three options below differ in where the scope signal lives and how deeply each keeps the provider interface.

**Option A — nullable post plus a `scope` discriminant (recommended)**

```php
// Core/Artifact/Discovery_Context.php (revised)
final readonly class Discovery_Context {
    public function __construct(
        public \WP_Post|null $post,                     // null for site-wide calls
        public string $scope = 'page',                  // 'page' | 'site'
    ) {}
}
```

A per-page call passes `scope: 'page'` and a real post; a site-wide call passes `scope: 'site'` and `post: null`. Every provider can branch on `$context->scope` or guard with `$context->post !== null`. The interface signature (`advertise( Discovery_Context )`) is unchanged — the callers change their constructor call, not the contract. Existing per-page providers that access `$context->post` without guarding become unsound under a site-wide call, so a nullable post is a visible reminder that the guard is needed; PHPStan at max level will flag unguarded `$context->post->...` dereferences.

Trade-offs: touches the `Discovery_Context` value object and every provider that constructs one (the two construction sites are `Markdown\Discovery::render()` and `Markdown\Request_Handler`); requires all three providers to be audited for the `null` case; is the smallest change to the committed interface (the method signature is not touched).

**Option B — a second context type**

Add a `Site_Discovery_Context` class (no `post`, with its own `scope` flag or as a distinct type) and change the `Provider` interface to two methods — or add an overload. This is the most type-safe shape but requires a breaking interface change, which ADR-0006 asks to avoid. It dissolves the deep-module guarantee for any third-party implementer of `Provider`, and creates two advertise paths the registry walker must dispatch between. Not recommended.

**Option C — a separate `advertise_site(): array` method on `Provider`**

The cleanest domain model (the two questions are semantically distinct) but again a breaking change to the committed interface: every existing provider must add a method. If the interface is treated as public API, this is a harder change than Option A. The spec's ADR-0006 rationale — "design the external interface as if it cannot be changed" — counsels against it unless there is a strong reason to distinguish the two calls at the type level.

**Recommendation:** Option A. It is the only choice that keeps the `Provider` method signature stable, is a one-line change to a value object, and is backward-compatible with all existing callers (they already pass a `WP_Post` and do not inspect `scope`, so they continue to work). The nullable post is a PHPStan-visible signal that the site-wide path exists. The cost — all three providers must guard against `$context->post === null` — is minimal because the two llms providers already return `[]` unconditionally and the Markdown provider's guard is a single `if ( $context->post === null ) { return []; }`. When Release 3 lands, the only code changes outside the new module are: `Discovery_Context` gains the nullable post and the `$scope` property; `Markdown\Discovery::render()` passes `scope: 'page'` (it currently omits the argument, which would default correctly if the default is kept); no provider method signature changes.

### 1b. Which `rel` for llms.txt

No IANA-registered link relation exists for llms.txt as of June 2026. Three options:

- `alternate` — semantically imprecise (implies a substitute representation of the page, not a site-wide index) but widely understood by agents and parsers.
- `related` — vague but RFC 8288–compliant for a non-standard relationship.
- A provisional extension relation, e.g. `https://llmstxt.org/ns/index` — RFC 8288 allows extension relations as URIs; this is future-proof if the llmstxt.org community registers a relation, but requires agents to understand the URI, which few do today.

**Recommendation:** use `related` for `llms.txt` and `llms-full.txt` in Release 3, in a `type="text/plain"` relation. The relation string is carried inside `Link_Relation::$rel`, which is a plain `string`, so it is trivially revised when IANA registration occurs — no interface change is needed. Include a comment in the provider's `advertise()` noting that the relation is provisional. The plan already budgets for this in §6 (out of scope: IANA registration).

### 1c. Where headers are emitted, and whether the early-router path also emits them

**Hook choice — `send_headers` vs `template_redirect`**

`send_headers` fires before `template_redirect` and before the theme touches `$wp_query`; it is the correct hook for emitting HTTP headers on an HTML response (WordPress uses it for `X-Pingback` and other per-request headers). `template_redirect` fires later and is the hook the llms request handler already uses to intercept artifact requests (priority 0). Using `send_headers` for Link headers on HTML responses avoids any risk of conflating the two code paths and is consistent with WordPress convention.

**Recommendation:** hook `send_headers` (default priority) for the HTML Link-header emitter. Only emit when WordPress is serving an HTML response — i.e., when `is_singular()` or when a site-wide advertisement is appropriate; see §4 for the full conditional logic.

**Early-router exit and Link headers**

The early serve router (`Plugin::serve_early()`) reads the cache and calls `exit` before WordPress's hook system runs. This means that when a warm `.md` or `/llms.txt` request is served from the cache, no WordPress hook fires — including `send_headers` — and no Link headers are emitted on those responses. This is intentional and correct: the artifacts being served *are* the things being advertised; advertising a `.md` file in its own `Link` header is redundant. The architecture text confirms: "An HTML request is never short-circuited, so module (c) decorates it through normal hooks." HTTP Link-header discovery is therefore **HTML-page-only in this plugin's design**. Agents that follow the advertised `.md` URL or request `/llms.txt` directly do not need a secondary Link header on those responses.

**Consequence:** the Link headers module cannot add headers to early-router responses. This is not a limitation — it is the intended split: the early router is the delivery path for artifacts; HTML pages are the discovery path for agents that have not yet found the artifacts.

## 2. Contracts

### 2.1 `Links\Module`

```php
namespace Kntnt\Ai_Visibility\Links;

use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Module;

/**
 * Release-3 module: emits RFC 8288 Link headers on HTML responses.
 *
 * @since 0.3.0
 */
final class Module implements Module_Contract {

    /**
     * Boots the module against the Core service graph.
     *
     * Registers the Link-header emitter; does not register a provider,
     * a serve pattern or a settings section (the module only reads the
     * registry, never writes to it).
     *
     * @since 0.3.0
     *
     * @param Core $core The Core service facade.
     * @return void
     */
    public function boot( Core $core ): void;

}
```

### 2.2 `Links\Header_Emitter`

The emitter is the sole internal class in the module. It holds a reference to the `Registry` and emits one `Link:` header per `Link_Relation` contributed by every provider.

```php
namespace Kntnt\Ai_Visibility\Links;

use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Registry;

/**
 * Emits RFC 8288 Link headers on HTML responses.
 *
 * On a singular page, passes a page-scoped Discovery_Context; on non-singular
 * pages (archive, home, search), passes a site-scoped context so providers
 * can still emit site-wide relations. Per-page providers return [] for the
 * site-scoped call; site-wide providers return [] for the page-scoped call.
 *
 * @since 0.3.0
 */
final class Header_Emitter {

    /**
     * Binds the emitter to the provider registry.
     *
     * @since 0.3.0
     *
     * @param Registry $registry The artifact-provider registry.
     */
    public function __construct( private readonly Registry $registry ) {}

    /**
     * Registers the send_headers hook.
     *
     * @since 0.3.0
     *
     * @return void
     */
    public function register(): void;

    /**
     * Emits Link headers for the current request.
     *
     * Skipped on non-HTML responses (admin, REST, feeds, robots.txt).
     * Delegates scope to the providers via Discovery_Context.
     *
     * @since 0.3.0
     *
     * @return void
     */
    public function emit(): void;

}
```

### 2.3 Core seam change — `Discovery_Context`

The only Core change is to `classes/Core/Artifact/Discovery_Context.php` (per Option A, §1a):

```php
final readonly class Discovery_Context {
    public function __construct(
        public \WP_Post|null $post,
        public string $scope = 'page',  // 'page' | 'site'
    ) {}
}
```

Existing construction sites (`Markdown\Discovery` and `Markdown\Request_Handler`) pass a non-null `WP_Post`; the `scope` argument defaults to `'page'` so they need no change. The `Header_Emitter` constructs `new Discovery_Context( $post, 'page' )` on singular pages and `new Discovery_Context( null, 'site' )` on non-singular ones. Every provider that accesses `$context->post` must guard against `null`; PHPStan at max level will flag unguarded dereferences.

### 2.4 Provider updates

Each provider's `advertise()` method body is updated (no signature change):

- `Markdown\Page_Markdown_Provider::advertise()` — add a guard: return `[]` when `$context->post === null`. The rest of the body is unchanged.
- `Llms\Index_Provider::advertise()` — replace `return []` with the site-wide relation (see §3). Guard: return `[]` when `$context->scope !== 'site'`.
- `Llms\Full_Provider::advertise()` — same pattern as `Index_Provider`.

## 3. What each provider advertises in Release 3

| Provider | Scope | `rel` | `type` | Condition |
|---|---|---|---|---|
| `Markdown\Page_Markdown_Provider` | page | `alternate` | `text/markdown` | singular, eligible, published post |
| `Llms\Index_Provider` | site | `related` | `text/plain` | always (site-wide call) |
| `Llms\Full_Provider` | site | `related` | `text/plain` | always (site-wide call) |

**Markdown alternates** — the provider already returns the page's `.md` relation with `rel="alternate"` and `type="text/markdown"` on a page-scoped call (see `Markdown\Page_Markdown_Provider::advertise()`). In Release 3 the same data is emitted as an HTTP `Link` header in addition to the existing HTML `<link>` tag — no change to the provider logic, only an additional consumer (the emitter) in the registry walk.

**llms.txt singletons** — both providers currently return `[]` (llms-txt.md §4.7, decision 8). In Release 3 they return a site-wide `Link_Relation` when called with `scope = 'site'`:

```php
// Llms\Index_Provider::advertise() — Release 3
public function advertise( Discovery_Context $context ): array {
    // Site-wide artifact; not relevant to per-page discovery.
    if ( $context->scope !== 'site' ) {
        return [];
    }
    // Relation is provisional (no IANA registration as of Release 3).
    // Revisable: change $rel when a standard relation is registered.
    return [ new Link_Relation( home_url( '/llms.txt' ), 'related', 'text/plain' ) ];
}
```

`Llms\Full_Provider` returns the equivalent for `/llms-full.txt`. The `$rel` is `'related'` per the recommendation in §1b; a one-line change when IANA registration occurs.

On a singular page the emitter constructs a page context and a site context, collects relations from both calls, deduplicates by `href` and emits all of them — so a singular page receives both its `.md` `Link` header and the site-wide `/llms.txt` relation in the same response.

## 4. Interaction with the early router

The early router (`Serve_Router`, called from `Plugin::serve_early()`) exits before WordPress loads when a warm artifact is found in the file cache. No WordPress hook fires on that path — including `send_headers`. This is not a problem for the Link headers module because:

1. The artifact being served *is itself* the thing that would be advertised; there is no need to advertise a `.md` file in its own `Link` header.
2. The module's consumer is an HTML page, not an artifact response. The architecture text is explicit: "An HTML request is never short-circuited, so module (c) decorates it through normal hooks."

The `send_headers` hook therefore fires exclusively on HTML responses (WordPress's standard lifecycle). The emitter's `emit()` method should skip non-HTML contexts as a belt-and-braces guard:

```php
public function emit(): void {
    // Link headers are HTML-page discovery only; skip admin, REST, feeds.
    if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
        return;
    }
    // Collect per-page relations on singular pages.
    $relations = [];
    if ( is_singular() ) {
        $post = get_queried_object();
        if ( $post instanceof \WP_Post ) {
            $page_ctx = new Discovery_Context( $post, 'page' );
            foreach ( $this->registry->providers() as $provider ) {
                array_push( $relations, ...$provider->advertise( $page_ctx ) );
            }
        }
    }
    // Always collect site-wide relations (llms singletons).
    $site_ctx = new Discovery_Context( null, 'site' );
    foreach ( $this->registry->providers() as $provider ) {
        array_push( $relations, ...$provider->advertise( $site_ctx ) );
    }
    // Deduplicate by href and emit one header value per relation.
    $seen = [];
    foreach ( $relations as $relation ) {
        if ( isset( $seen[ $relation->href ] ) ) {
            continue;
        }
        $seen[ $relation->href ] = true;
        header( sprintf(
            'Link: <%s>; rel="%s"; type="%s"',
            esc_url_raw( $relation->href ),
            $relation->rel,
            $relation->type,
        ), false );  // false = allow multiple Link headers
    }
}
```

The second argument `false` to `header()` is essential: PHP's default replaces a header when one with the same name already exists. RFC 8288 requires a separate `Link:` header line per relation (or a comma-separated value in one header); using `false` appends additional `Link:` headers rather than overwriting.

## 5. Testing strategy

### 5.1 Unit tests (Pest + Brain Monkey)

- **`Discovery_Context`** — construction with both `post` and `scope`; null post with `'site'` scope; default scope is `'page'`.
- **`Markdown\Page_Markdown_Provider::advertise()`** — returns `[]` on a site-scoped call (null post); returns the `.md` relation on a page-scoped call with an eligible post (existing test, regression).
- **`Llms\Index_Provider::advertise()`** — returns `[]` on a page-scoped call; returns the `/llms.txt` relation on a site-scoped call; the relation carries `rel="related"` and `type="text/plain"`.
- **`Llms\Full_Provider::advertise()`** — same shape, `/llms-full.txt`.
- **`Links\Header_Emitter::emit()`** — on a singular page: emits the per-page `.md` header and both site-wide relations; on a non-singular page: emits only the site-wide relations; on an admin request: emits nothing; on a REST request: emits nothing; deduplication: two providers contributing the same `href` produce one header; the `header()` call uses `false` (append, not replace).
- **`Links\Module::boot()`** — the emitter is registered; `send_headers` hook is wired.
- **Existing Markdown and llms suites** — kept green through the `Discovery_Context` nullability change; add the guard test for `Page_Markdown_Provider`.

### 5.2 Playground e2e

End-to-end assertions run on WordPress Playground with no DDEV fallback (ADR-0004). Each assertion follows the warm-path polling pattern established in the Release-1 and Release-2 suites.

- A `GET` to a singular published page returns a `Link:` header with the `.md` alternate (`rel="alternate"`, `type="text/markdown"`).
- The same response also carries `Link:` headers for `/llms.txt` and `/llms-full.txt` (`rel="related"`, `type="text/plain"`).
- A `GET` to the blog index (non-singular) returns `Link:` headers for the singletons only — no per-page `.md` relation.
- A `GET /about.md` (early-router cache hit) returns **no** `Link:` headers (the early router exits before WordPress; this is correct behaviour).
- A `GET /llms.txt` (early-router cache hit) returns **no** `Link:` headers (same reason).
- A `HEAD` to a singular page returns the same `Link:` headers as `GET` (headers-only response).
- After the llms providers return non-empty relations in Release 3, verify that the Markdown `<link>` tags in `<head>` are **unchanged** (the `Markdown\Discovery` path is unaffected).
- A subdirectory install: confirm the URLs in the `Link:` headers are home-relative (`/sub/llms.txt`, `/sub/about.md`).

If Playground's php-wasm cannot exercise a behaviour (e.g. inspecting raw HTTP headers via `header_list()`), STOP and raise to the maintainer — never wire a DDEV fallback.

## 6. Build order (test-first)

Steps 1–2 extend Core and existing providers; step 3 is the new module. Keep all existing tests green throughout.

1. **`Discovery_Context` + provider guards** — make `post` nullable, add `scope`, update `Markdown\Page_Markdown_Provider::advertise()` to guard against `null`, confirm existing suites green.
2. **`Llms\Index_Provider::advertise()` + `Llms\Full_Provider::advertise()`** — replace `return []` with the site-scoped relation; add unit tests for both scopes.
3. **`Links\Header_Emitter` + `Links\Module`** — `Header_Emitter` first (unit tests for every conditional branch), then `Module::boot()`, then wire `Links\Module` into `Plugin` after the llms module boot line.
4. **Playground e2e** — all assertions in §5.2.

## 7. Out of scope for Release 3

- IANA registration for an llms.txt link relation; the provisional `related` value is revisable with a one-line change.
- Per-post advertising beyond the existing `<link rel="alternate">` in `<head>` — the HTTP header carries the same data, no new per-post signals.
- `robots.txt` content signals (Release 4, Content Signals module).
- Link headers on artifact responses (`.md`, `/llms.txt`): the early router exits before WordPress; this is intentional, not a gap.
- Changing `Markdown\Discovery` (the HTML `<link>` emitter): it is deliberately kept unchanged; Release 3 adds a parallel HTTP path without altering the HTML path.
- A Content-Type matrix column for the Link headers module: the module consumes existing providers and requires no new capability column.
- Trailing-slash normalisation and redirect behaviour for singletons: inherited from Release 2's out-of-scope list.

---

## Decisions (ratified 2026-06-23)

All four are settled; the implementation contract is `plans/013-build-link-headers-module.md`. Summary: **(1)** Option A, simplified — `Discovery_Context` gains a nullable post and **no** `$scope` field (providers branch on `post === null`); **(2)** `rel="related"`, `type="text/plain"` for both singletons; **(3)** emit on **every** HTML page, with the guard extended to skip `is_robots()` and `is_404()`; **(4)** deduplicate by the full `(href, rel, type)` triple, not by `href` alone.

The original options, for the record:

1. **Discovery-context scope (§1a):** approve Option A (make `Discovery_Context::$post` nullable, add `$scope: 'page'|'site'`) or choose Option B (second context type) or Option C (separate `advertise_site()` method). The recommended choice is Option A.
2. **Link relation for llms.txt (§1b):** approve `rel="related"` as the provisional relation for `/llms.txt` and `/llms-full.txt`, or choose `rel="alternate"` (imprecise but widely understood) or a custom extension URI (future-proof but opaque to current agents). The recommended choice is `related`.
3. **Non-singular pages (§4):** confirm that the emitter should emit site-wide (`/llms.txt`, `/llms-full.txt`) `Link:` headers on **every** HTML page (singular and non-singular alike), not just on singular pages. This is the recommended behaviour (maximises discoverability) but widens the Release-2 per-page assumption; the maintainer should sign off.
4. **Deduplication strategy (§4):** confirm that deduplication by `href` is sufficient, or specify a different strategy (e.g. by `rel+type` pair) if two providers could legitimately contribute the same URL with different relations.
