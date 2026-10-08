# Spec 2 — Release 2: llms.txt and llms-full.txt (llms.txt module + Core extensions)

This is the Release-2 specification for the **llms.txt module**: the two singleton discoverable artifacts `llms.txt` (a curated, machine-readable index of the site's key content) and `llms-full.txt` (the site's full content concatenated as Markdown). It turns the architectural seams of [`docs/architecture.md`](../architecture.md) and ADR-0006 … 0010 into concrete contracts an implementer can TDD against, mirroring the shape and rigour of the Release-1 spec [`docs/spec/markdown-alternate.md`](markdown-alternate.md). Where this spec states *what*, the linked ADR states *why*; read the ADR when the reasoning matters.

Scope of this spec: the **Core seams Release 2 needs that Release 1 left module-private** (a shared eligibility predicate and enumeration — surfaced as a Core per-type capability matrix — the markdown-alternate identity/URL scheme, the single-flight materialiser, and an exact-path mode for the serve router), plus the **llms.txt module** itself (two singleton providers, the index and full builders, the request handler and the cache-version invalidation). The Release-1 Core seams were designed for this exact consumer (ADR-0006); this spec promotes the concepts Release 1 placed inside the Markdown module to Core, because Release 2 is the second consumer that ADR-0006 said would earn the shared seam. Pre-1.0, that promotion is a free refactor — no migrations, no backwards-compatibility (AGENTS.md ground rules).

## 1. Decisions (settled)

The open forks have been settled; build to these. Decisions 1–3 and 8 follow from the architecture and the ADRs/GLOSSARY.md; decisions 4–7 were settled with the maintainer for this spec, and decision 9 is the security-preserving router approach confirmed with the maintainer.

1. **Convention serve paths.** The artifacts live at the fixed root paths `/llms.txt` and `/llms-full.txt` — the llmstxt.org discovery model, like `robots.txt` (ADR-0008). On a subdirectory install they are home-relative (`/blog/llms.txt`), resolved the same way as the `.md` keys.
2. **llms-full.txt is concatenation, never re-render.** It is assembled from the per-page **Markdown alternates** via the Core `Page_Markdown` service — the same Markdown, concatenated — never a second HTML render (GLOSSARY.md, ADR-0007).
3. **Aggregates regenerate lazily under a cache-version stamp — not delete-on-change.** A per-entity artifact (a page's own `.md`) is deleted on change and regenerates immediately; the aggregates' cost is O(site), so they are invalidated by **bumping the cache-version stamp** and rebuilt lazily on the next request, with a TTL safety net (architecture §Caching, ADR-0007). No cron pre-generation.
4. **Links point to `.md` alternates.** Each indexed page links to its `.md` URL, not its canonical HTML URL — the llmstxt.org preference for clean Markdown and our differentiator. Every indexed page is eligible, so its `.md` always exists; the `.md` carries `rel="canonical"` back to the HTML, so there is no duplicate-content risk.
5. **Descriptions and titles are core-first, with an override filter.** The link label is the **post title** (`get_the_title()`) — which is the basis of both the `<title>` element and the on-page H1 for singular content, minus the site-name noise — and the description is the **excerpt** (`get_the_excerpt()`). A per-entry filter (`kntnt_ai_visibility_llms_entry`) lets the owner or an SEO integration substitute the real `<title>`/meta description. This is fully dependency-free; the literal `<meta name="description">` has no core source (it exists only when an SEO plugin or theme creates it, often as an unresolved template), so it is reached through the seam, not bundled.
6. **A Core per-type capability matrix governs which content types get which artifact.** The settings page presents one row per front-end-viewable post type with three checkbox columns — **Markdown (`.md`)**, **In `llms.txt`**, **In `llms-full.txt`** — composed by Core from columns the modules register, and built so Releases 3–4 can add their own columns. The `llms.txt` and `llms-full.txt` columns **require** `.md` for that row (the index links to `.md`; the full file concatenates `.md`), enforced at save. Zero-config defaults: `.md` on for every viewable type, `llms.txt` inheriting `.md` (on), and **`llms-full.txt` on for Pages only** — so a small site automatically gets a useful full-text file of its cornerstone pages while a large archive is never dumped wholesale. Each column is mirrored by a developer filter (`kntnt_ai_visibility_eligible_post_types`, `…_llms_post_types`, `…_llms_full_post_types`). There is no per-post override.
7. **Served as `text/plain; charset=utf-8`.** Both files carry the `.txt` extension and render inline in a browser; this is what `robots.txt`-style files and the llms.txt convention use. The on-disk cache file keeps the Core store's universal `.md` extension — an internal detail; the served Content-Type comes from the serve pattern, not the file name.
8. **No advertising in Release 2.** llms.txt is convention-discovered (agents look for `/llms.txt` like `/robots.txt`); it is **not** advertised per page (ADR-0008). Whether to additionally advertise it site-wide, and via which link relation, stays a Release-3 concern (module c). The providers still expose their (empty, for now) discovery descriptors so Release 3 can decide.
9. **Router gains exact-path matching, not a weakened key scheme.** Singleton paths need exact-path matching; a `.txt` *suffix* rule would also claim every other `.txt` path. The router gains an exact-match capability **alongside** the `.md` suffix match, with the strict `SAFE_KEY` whitelist and the realpath containment unchanged and still applied. Path-traversal payloads against the new paths are a mandatory test fixture.

## 2. Core reconciliation — extract shared seams during 2.x

Release 1 placed several concepts inside the Markdown module that Release 2 is the second consumer of. ADR-0006 is explicit: *DRY extracts a Core service only where modules share the same concept* — and these are the same concept, not merely similar code. Promote them to Core as the slice lands, keeping the 163 Release-1 tests green through the refactor (red→green→refactor: behaviour is preserved, tests move to the new seam):

- **Eligibility and the post-type selection** (§3.1). The hard guard (published, front-end-viewable, not an attachment) is the security rule that lets the early router serve before WP auth; both the `.md` provider and the llms enumeration depend on it, and `llms-full.txt` must concatenate *exactly* the `.md`-eligible set or links break. The predicate moves to `Core\Eligibility`, and the post-type selection — a Markdown-only text field in Release 1 — becomes the **Core content-type matrix** (`Core\Content\Content_Types`, §3.1 and §6): one row per viewable type, one column per artifact, composed by Core from columns the modules register. This dissolves the cross-module coupling entirely — the selection is a Core concept and each module reads only its own column.
- **The markdown-alternate identity/URL scheme** (§3.2). The cache key (`key_for`) and the advertised `.md` URL (`md_url`) for a post are the identity of the *markdown-alternate kind*, which Core stores and serves. `llms-full.txt` needs the identity to materialise each page's Markdown, and the index needs the URL to link it. The scheme moves to a Core locator; the Markdown provider delegates to it.
- **The single-flight materialiser** (§3.3). The lock-and-cache stampede guard in `Page_Markdown_Service::materialise()` is reused by the aggregates (the O(site) `llms-full.txt` is exactly where single-flight matters most). It moves to a small `Core\Cache\Single_Flight` both consumers call.

The `Core` facade grows accessors for the new services (`eligibility()`, `content_types()`, `markdown_alternate()`, `single_flight()`), wired in `Plugin`. The Release-1 clear-cache action moves to Core beside the matrix (it flushes the whole cache, which now includes the llms files); with the post-type field gone, neither the Markdown nor the llms module registers a settings section of its own — each contributes only its matrix column.

## 3. Core extensions — contracts

Namespaces follow the repo convention (`Kntnt\Ai_Visibility\…`, `Pascal_Snake_Case` classes, PSR-4 under `classes/`). These are committed seams — designed as if they cannot change (ADR-0006) — refined in detail during TDD.

### 3.1 Eligibility and the content-type matrix → Core

Two Core collaborators replace the Markdown module's private eligibility and its post-type text field. `Core\Content\Content_Types` is the matrix — modules register a capability column, Core composes and stores them. `Core\Eligibility` is the runtime predicate and enumeration the providers and builders use.

```php
namespace Kntnt\Ai_Visibility\Core\Content;

// One capability column in the settings matrix — one per artifact kind.
final readonly class Capability_Column {
    public function __construct(
        public string $key,        // 'md' | 'llms' | 'llms_full'
        public string $label,      // column header, e.g. 'Markdown (.md)'
        public string $requires,   // '' or a column key it depends on (forced off when that is off)
        public \Closure $default,  // fn(string $type): bool — the zero-config default for a cell
    ) {}
}

interface Content_Types {
    public function register_column( Capability_Column $column ): void;  // a module contributes its column
    public function columns(): array;                                    // list<Capability_Column>, registration order
    public function rows(): array;                                       // list<string> — viewable, non-attachment types
    public function is_enabled( string $type, string $key ): bool;       // saved value or default, dependency-enforced
    public function types_for( string $key ): array;                     // list<string> — rows where is_enabled($type, $key)
}
```

```php
namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Content\Content_Types;

final class Eligibility {
    public function __construct( private Content_Types $types ) {}

    // The universal hard guard: published, front-end-viewable, not an attachment.
    // The rule that lets the early router serve before WP auth.
    public function is_servable( \WP_Post $post ): bool;

    // is_servable() AND the post's type has the `.md` capability.
    public function is_eligible( \WP_Post $post ): bool;

    // The effective Markdown types after the developer filter and universal
    // viewability/non-attachment guard; shared with aggregate selection.
    public function md_types(): array; // list<string>

    // Published, non-password-protected posts of the given types, grouped in the
    // order the types are passed and ordered within each type for the aggregates
    // (hierarchical types by menu_order then title, others by date descending).
    // May run one query per type so each type keeps its own ordering.
    public function enumerate( array $types ): array; // list<\WP_Post>
}
```

- The Markdown module registers the `md` column in `boot()` (`default: fn() => true` for every viewable type); the llms module registers `llms` (`requires: 'md'`, `default: fn() => true`) and `llms_full` (`requires: 'md'`, `default: fn( $type ) => $type === 'page'`). Core owns the matrix; modules own their columns — the same contribute/compose shape as the artifact registry and settings sections (ADR-0006, ADR-0010).
- `is_enabled()` returns the saved cell value, or the column's default closure when unset, and forces the cell off when its `requires` column is off — the subset guarantee (you can never index or concatenate a `.md` that does not exist).
- `Core\Eligibility::md_types()` owns the effective `.md` set: `types_for('md')` through `kntnt_ai_visibility_eligible_post_types`, then restricted to viewable, non-attachment types. Valid additive filter overrides remain possible, including a viewable type disabled in the saved matrix. The llms sets are `types_for('llms')`/`types_for('llms_full')` through `…_llms_post_types`/`…_llms_full_post_types`, each intersected with this effective `.md` set **after its aggregate filter** so a filter cannot re-add a Markdown-disabled type. The provider and discovery use `Core\Eligibility::is_eligible()`, which reads the same effective set; `enumerate()` intersects its requested types with that set again before querying and passes returned posts through `is_eligible()`, so query integrations cannot indirectly materialise an excluded type.
- `enumerate()` queries `post_status => publish`, `has_password => false`, `posts_per_page => -1`, `no_found_rows => true`, one query per type so each keeps its own order, and returns the posts grouped in the passed types' order. **Password-protected posts are excluded** — the aggregates materialise the per-page cache that the early router serves before WP auth, so protected content must never be cached or concatenated (architecture §Caching). This is the leak the Release-1 `.md` path avoids by 403-ing before it caches; the aggregation closes it at the source. It is the O(site) read the aggregates share; the per-page Markdown it feeds on is itself cached, so the cost is I/O, not rendering (ADR-0007).
- **Path exclusions** (Settings → Excluded paths, added post-Release-2): `Core\Content\Exclusions` reads a newline-separated list of regular-expression bodies, wraps each as `#…#iu` (a `#` delimiter cannot occur literally in a path; `u` matches non-ASCII slugs, `i` is case-insensitive) and matches it against each post's rawurldecoded, home-relative path — never the host, so a careless pattern cannot exclude the whole site, and a root-relative pattern is portable across hosts and subdirectory installs. Both `is_eligible()` and `enumerate()` consult it, so a matched path is curated out of the per-page `.md`, `/llms.txt` and `/llms-full.txt` alike. An invalid pattern is reported and dropped at save time, and a changed pattern set bumps the cache version and flushes the cache so the change takes effect on the next request. Two filters extend it: `kntnt_ai_visibility_exclusion_patterns` (the parsed list) and `kntnt_ai_visibility_is_excluded` (the per-post verdict).

#### Exposure-policy deployment

Eligibility and aggregate-selection filters are site-wide integration-code policies. Their callbacks must be deterministic for public traffic and registered before the plugin bootstrap (for example in a must-use plugin). A code deployment does not save the plugin's settings and does not automatically invalidate existing artifact files. The early router may serve those files before WordPress routing or theme callbacks, so changing a filter alone cannot revoke warm bytes.

When deploying a changed Markdown eligibility, aggregate-selection or path-exclusion filter, pause public artifact traffic and drain existing artifact writers, deploy the new policy, then use **Settings → AI Visibility → Clear cache**. That authorised action bumps the aggregate cache version and flushes every plugin-owned artifact file, including per-page Markdown. Purge any external artifact or page cache that serves those URLs, then resume traffic. Verify an excluded page's `.md` returns 404, its HTML has no Markdown discovery link and neither aggregate contains its link or content. A version bump alone is insufficient because per-page cache identities are not version-stamped. This deployment purge applies to code-driven policy changes; settings-option lifecycle invalidation remains a separate Core responsibility.

### 3.2 Markdown-alternate locator → Core

The cache key and `.md` URL for a post are the identity of the markdown-alternate kind. Core owns the kind's storage and serving, so it owns the scheme.

```php
namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;

final class Markdown_Alternate {
    public const KIND = 'markdown-alternate';

    // The cache identity: KIND, the home-relative key and the source ID.
    // An unprefixed home uses 'index'; source-language prefixes survive.
    // Ordinary index leaves append '/index'. Root and subdirectory installs
    // derive the same key. Plain permalinks use 'plain/<stored post ID>'.
    public function identity_for( \WP_Post $post ): Identity;

    // The absolute `.md` URL advertised and linked for a post: the source-language
    // home plus '/index.md' for a static front. Ordinary index leaves append
    // '/index.md'; other pages append '.md' to the permalink minus its trailing slash.
    public function url_for( \WP_Post $post ): string;

    // The HTML source canonical, including its Bogo language policy.
    public function canonical_url_for( \WP_Post $post ): string;
}
```

- The locator owns `key_for`, the advertised URL and `home_relative`; the Markdown provider and both aggregates use this same identity scheme. Home `index` keys are reserved for configured static fronts. An ordinary canonical index leaf gains one additional `index` segment in both its key and its dedicated URL, at every nesting depth and with source-language prefixes preserved. Dedicated-path resolution reverses this escape; canonical negotiation uses the original page path. No shorter compatibility aliases are provided.
- The llms index builder calls `url_for()` for each link; the llms full builder calls `identity_for()` to materialise each page's Markdown.
- Plain permalinks use distinct `plain/<stored post ID>` cache keys for every source, including static fronts. The locator composes `format=markdown` onto the canonical query URL; the index advertises that reachable form and the full builder materialises the same source identities. No raw query text enters a key, and no plain `.md` or synthetic path is advertised or registered for early serving. Both canonical query and `Accept` forms are supported as specified in [Markdown §4.2](markdown-alternate.md#42-request-forms-routing-and-precedence-adr-0009).
- The `.md` URL for a non-ASCII slug is still a valid URL — it just resolves through the uncached PHP path (the Release-1 deferred limitation), so the link works; it is simply not early-cached. No new issue for Release 2 (§10).

### 3.3 Single-flight materialiser → Core

```php
namespace Kntnt\Ai_Visibility\Core\Cache;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;

final class Single_Flight {
    public function __construct( private Store $store /* + lock dir */ ) {}

    // Return the cached bytes when present; otherwise hold a per-family
    // advisory lock, re-check the cache, run $produce, write and return — so
    // concurrent misses do not all generate. The lock lives outside the cache
    // tree (system temp dir) so locking never depends on the cache existing.
    public function once( Identity $identity, callable $produce, ?callable $validate = null ): Materialisation;
}
```

- `Page_Markdown_Service::materialise( $identity, $post )` becomes `$this->single_flight->once( $identity, fn() => $this->for_post( $post ) )`. The lock/flock helpers move to `Single_Flight`.
- The llms request handler materialises each aggregate through `once()` with the provider's `generate()` as the producer. The returned `Core\Cache\Materialisation` separates valid `bytes` from `persisted`; a storage failure returns the generated bytes with `persisted=false`, while a producer exception propagates without a result or cache publication. See [the shared persistence-failure contract](markdown-alternate.md#51-unavailable-persistence). Both llms singletons use its uncached HTTP fallback, including byte-based validators, bodyless HEAD/304 and `no-store`. Failed publication must not trigger sibling pruning, even if an older file at the same path still exists.

Stampede locks use one stable inode per cache base and registered artifact kind, independent of document keys or aggregate versions. The default namespace is resolved lazily beneath the system temporary directory as `kntnt-ai-visibility-locks-{sha256(cache base)}`. Injected directories are used verbatim without creation; filenames still include the cache-base namespace, so installations sharing an injected directory do not contend. This bounds historical lock growth to the installed kinds and serialises cold work within each kind. Nested generation in the same PHP process shares a held family handle across separate same-base materialisers, while other processes remain excluded. Release, invalidation and deactivation never unlink these stable stampede inodes: a queued waiter and a third writer must continue to contend on the same inode. The separate short publication barrier retains its own store-owned inode and revocation state.

If default-directory creation, opening or `flock` fails, Core emits a controlled logger diagnostic without filesystem warnings in the HTTP body. A failed lock is never successful ownership: current validated bytes may be generated, but are returned with `persisted=false` and are not written without exclusion. An already valid cache hit remains usable. Publication-generation and source validation still apply, so obsolete work refuses instead of using this fallback. The existing uncached HTTP contract supplies no-store responses for valid bytes.

`Store::publication()` owns the shared `Publication_Barrier` for its stable cache base. `Single_Flight` captures its generation before validation, warm-cache reads or waiting for the stampede lock. It validates supplied work before a hit, after waiting and after producing, then compares the captured generation and writes under the barrier's same short lock. `File_Store::delete()` and `flush_all()` advance that generation and remove files under the same lock, including when no file exists. Rendering and eligibility callbacks run outside this short critical section; separate same-base store objects reuse a request's already-held handle when a callback re-enters revocation. The stable coordination inode lives outside the removable cache tree and must not be unlinked while producers or waiters can exist.

Before erasure, the same stable coordination inode durably marks the whole store unreadable. Verified single-file deletion clears its temporary mark only if the store was previously readable; a failed deletion or whole-cache erasure retains poison across fresh store and early-router objects. Poisoned stores miss reads and decline persistence, so current validated generation can still return valid bytes with `persisted=false`. Only a verified whole-cache flush after storage repair clears durable poison. Existing empty, malformed or interrupted coordination records fail closed; exclusive file creation initialises new state, and complete records require an explicit readability flag and final terminator so a short write cannot become a valid readable prefix. Controlled logger diagnostics identify failed erasure and refused cache reads. A filesystem that forbids erasure can retain physical files outside PHP's control: repair the deployment and flush the whole cache; the PHP router's refusal cannot remove a file served directly by a separately configured webserver.

Production page publication checks the queued source's stored fields, password, current effective eligibility, complete locator identity and canonical URL against fresh WordPress reads before a hit or publication. Its scope bypasses request and persistent object caches, creates a fresh exclusion gate and initialises a clone of the existing rewrite object, retaining registered custom permastructs. It restores the exact caller cache and rewrite objects without seeding or flushing persistent entries. This rejects queued old content even when a plain numeric key is unchanged, or when a static-front change affects only canonical metadata. Inline `for_post()` uses `Single_Flight::uncached()` with the same validation and generation checks; the source-specific rendering context remains unchanged.

Obsolete work throws `Obsolete_Artifact` (a `DomainException`) without a result, successful response or file publication. This refusal is distinct from valid bytes with `persisted=false`. Both HTTP shells return plain-text, `no-store`, `nosniff` 403, without validators or a HEAD body. A failed nested page or final aggregate filter invalidates the whole aggregate; a subsequent request may rebuild from current public sources. Aggregate validation also rejects an identity selected before a newer cache version.

### 3.4 Serve_Pattern + Serve_Router: exact-path singletons

`Serve_Pattern` becomes the descriptor the router needs to both match a path and synthesise headers without consulting a provider (the early serve must stay provider-free for speed). It carries two match modes, the Content-Type, whether a `rel="canonical"` back-link applies, and whether the cache key carries the cache-version.

```php
namespace Kntnt\Ai_Visibility\Core\Artifact;

final readonly class Serve_Pattern {
    // Suffix-matched shape (Release 1 `.md`): the key is derived from the path,
    // and a canonical HTML back-link applies. Content-Type defaults to Markdown.
    public static function suffix(
        string $kind,
        string $suffix,
        string $content_type = 'text/markdown; charset=utf-8',
    ): self;

    // Exact-path singleton (Release 2 `/llms.txt`): a fixed home-relative path and
    // a fixed base key (never reflected from the URL), no canonical back-link, and
    // a version-stamped key by default so a cache-version bump invalidates it.
    public static function exact(
        string $kind,
        string $path,           // home-relative, e.g. '/llms.txt'
        string $key,            // fixed base key, e.g. 'llms'
        string $content_type = 'text/plain; charset=utf-8',
        bool $versioned = true,
    ): self;

    // Readable facets the router branches on: $match ('suffix'|'exact'), $suffix,
    // $path, $key, $content_type, $canonical (bool), $versioned (bool).
}
```

The Markdown provider's `serve_pattern()` is `Serve_Pattern::suffix( Markdown_Alternate::KIND, '.md' )` with pretty permalinks and null with plain permalinks. The registry omits nulls from its concrete dedicated-path allowlist; aggregate providers retain their exact patterns. The router changes:

- **Constructor** gains a lazy `cache_version` callback (`callable(): int`, default `static fn(): int => 1`), invoked **only** when an exact-versioned pattern matches — so a normal request (HTML, asset, `.md`) never reads the option. Wire it in `Plugin` to `static fn(): int => ( new Cache_Version() )->current()`.
- **`identify()`** branches per pattern mode. Suffix mode is unchanged (strip the leading slash and suffix, validate against `SAFE_KEY`). Exact mode: the home-relative path must equal `$pattern->path` exactly (case-sensitive); the key is `$pattern->key` plus, when versioned, `'-v' . ($cache_version)()`; the derived key is still validated against `SAFE_KEY` as defence-in-depth (it always passes — `llms`, `llms-full-v8` all match the whitelist). No part of the URL ever reaches the key in exact mode.
- **`is_artifact_path( string $path ): bool`** classifies a dedicated address from the registered serve-pattern list, using the same installation base and key validation, without reading a cache file. Canonical HTML negotiation yields registered artifact addresses to their own provider before setting page-cache bypass signals or `Vary: Accept`; this works on cold caches and avoids cross-module URL lists.
- **`resolve()`** returns the matched pattern alongside the contained realpath (e.g. a small `Resolved { string $path; Serve_Pattern $pattern; }`), so `serve()` can pick the Content-Type and decide the canonical link without re-matching.
- **`serve()` / `headers_for()`** take the Content-Type from the matched pattern (not the `CONTENT_TYPE` constant) and emit the `Link: <…>; rel="canonical"` **only** when `$pattern->canonical` is true (i.e. for `.md`). For the singletons there is no HTML canonical, so none is emitted.
- The realpath containment check, the null-byte rejection, the GET/HEAD gate and the TTL safety net are **unchanged** and still run for every served path.

**Adversarial fixtures (mandatory):** against both new paths — `/llms.txt/../../etc/passwd`, `/llms.txt%00`, `/llms.txt%2f..`, `/xllms.txt`, `/llms.txt.md` (must fall through: `.md` suffix derives key `llms.txt`, which fails `SAFE_KEY` on the dot), `/LLMS.TXT` and mixed case (exact match is case-sensitive → no match → fall through), a trailing slash, a query string, an absolute path and a symlink escape. Each must resolve to null (fall through) or a contained file, never a path outside the cache base.

## 4. The llms.txt module

The module is thin: two singleton providers, two builders, a request handler and an invalidation — plus the two matrix columns it registers with Core and reads its type sets back from. It depends only on Core abstractions and never reaches into the Markdown module (ADR-0006). New classes live under `classes/Llms/`.

### 4.1 Providers (index + full)

`llms.txt` and `llms-full.txt` are **singleton providers** — a rule, not an enumeration, like the markdown-alternate provider, but matching exactly one path each (ADR-0008). Each is thin: it matches its exact path, delegates generation to its builder, declares its exact serve pattern and advertises nothing in Release 2.

```php
namespace Kntnt\Ai_Visibility\Llms;

// kind 'llms-txt', base key 'llms', path '/llms.txt'.
final class Index_Provider implements Provider {
    public function match( Request $request ): ?Identity;        // exact '/llms.txt' → versioned identity
    public function generate( Identity $identity ): Artifact;    // delegates to Index_Builder; text/plain
    public function advertise( Discovery_Context $context ): array; // [] in R2
    public function serve_pattern(): Serve_Pattern;              // Serve_Pattern::exact('llms-txt', '/llms.txt', 'llms')
}

// kind 'llms-full', base key 'llms-full', path '/llms-full.txt'.
final class Full_Provider implements Provider { /* same shape, Full_Builder */ }
```

- `match()` reads the home-relative request path (the request handler builds the `Request`; the router strips the base for its own match). On an exact path match it returns the **version-stamped** identity, `Identity( kind, key . '-v' . $version, 0 )`, where `$version` is `Cache_Version::current()` (injected). The early router computes the same key from its `cache_version` callback, so both agree on the cache file.
- `generate()` returns an `Artifact( bytes, 'text/plain; charset=utf-8', last_modified )`. The `last_modified` is the build time (the aggregate has no single source post); the cache file's mtime is the validator the router uses on later serves, so `generate()`'s timestamp only matters for the first inline serve.
- `advertise()` returns `[]` (decision 8). The class exists so Release 3 can read a discovery descriptor without the provider interface changing.

### 4.2 Artifact type sets (from the Core matrix)

The llms artifacts own no selection of their own — they read their type sets from the Core matrix (§3.1):

- the index covers `types_for('llms')` through the `kntnt_ai_visibility_llms_post_types` filter;
- the full file covers `types_for('llms_full')` through the `kntnt_ai_visibility_llms_full_post_types` filter;
- each is intersected with `types_for('md')` after filtering, so a filter can never add a type with no `.md`.

By the matrix defaults (§3.1) the index lists every AI-visible type and the full file contains Pages only, until the owner ticks otherwise (decision 6).

### 4.3 llms.txt content (Index_Builder)

`Index_Builder` assembles the llms.txt Markdown from `Core\Eligibility::enumerate( types_for('llms') )` — so drafts and password-protected pages never appear — grouped into one H2 section per post type. Output shape (llmstxt.org convention):

```
# {site name}

> {site tagline}

Markdown index of {site name} for AI agents. Full text: /llms-full.txt

## Pages

- [About]({home}/about.md): We help content sites…
- [Contact]({home}/contact.md)

## Posts

- [Hello World]({home}/hello-world.md): A first post…
```

- **H1** — `get_bloginfo( 'name' )`. Filter `kntnt_ai_visibility_llms_title`.
- **Blockquote** — `get_bloginfo( 'description' )` (the tagline); omitted when empty. Filter `kntnt_ai_visibility_llms_summary`.
- **Intro line** — a single line that also references `/llms-full.txt` (intra-artifact discovery); omittable. Filter `kntnt_ai_visibility_llms_intro`.
- **Sections** — one H2 per selected type, ordered `page`, then `post`, then remaining types in registration order. The H2 label is the type's plural label (`get_post_type_object( $type )->labels->name`). Filter `kntnt_ai_visibility_llms_sections` over the ordered `[type => posts]` structure for full reordering/curation.
- **Items** — `- [{title}]({md_url}): {description}` where `title` is `get_the_title()` (entities decoded, whitespace collapsed to one line, and Markdown-significant characters such as `[`/`]`/backticks escaped so a title can never break the link syntax), `md_url` is `Core\Markdown_Alternate::url_for()` (decision 4), and `description` is `get_the_excerpt()` (tags and shortcodes stripped, entities decoded, collapsed to one line, length-capped to keep the index tight); the `: {description}` is omitted when the excerpt is empty. Filter `kntnt_ai_visibility_llms_entry` receives `{ title, url, description }` plus the `WP_Post` so an SEO integration can substitute the real `<title>`/meta description (decision 5).
- **Description length** — cap the cleaned, decoded description at 200 Unicode code points. A value of exactly 200 code points remains intact. For a longer value, keep the first 200 code points, trim trailing whitespace and append the single Unicode ellipsis `…` (U+2026), additional to the cap. Both fresh and cached llms.txt bytes must remain valid UTF-8. Use the UTF-8-aware `mb_*` functions supplied by WordPress's compatibility layer when native mbstring is absent; no additional extension requirement is introduced.
- **Final string** — filter `kntnt_ai_visibility_llms_txt` over the assembled document (the raw escape hatch, mirroring Release 1's front-matter filter).
- **No hard cap** by default — every selected post is listed; a very large site narrows via the matrix's `llms.txt` column or a filter. There is no `## Optional` section by default (no curation signal); both are reachable through the filters. Truncation, if a filter introduces one, must be logged, not silent (no silent caps).
- **Build cost** — auto-generating excerpts runs `the_content` once per post, so building the index is O(site) like `llms-full.txt`, not a free links-only pass. Both are version-stamped and lazily built (§5), so it is a one-time cost per cache generation, not per request.

### 4.4 llms-full.txt content (Full_Builder)

`Full_Builder` assembles `llms-full.txt` by concatenating each selected page's per-page Markdown — never a second render (decision 2).

The full aggregate uses the same [public theme-body renderer](markdown-alternate.md#431-public-theme-body-integration) as every per-page Markdown form. A source adapter's explicit public field output is converted once through Page-Markdown; renderer failure aborts the full build with `Core\Public_Content_Rendering_Failed`, never a partial successful aggregate. Valid empty content, conversion failure and unavailable persistence retain their separate contracts.

Both aggregate builders run their entire selection, metadata, per-page materialisation and final document filters in Core's anonymous rendering scope. An authenticated first request therefore cannot personalise either aggregate, including through its final escape-hatch filter. Each cold per-page materialisation enters the isolated source main query, active Loop and source locale documented in [Markdown §4.3](markdown-alternate.md#43-generation-pipeline-core-page-markdown), even though the aggregate request has no singular source. Successive translated sources cannot retain the previous source's context. Nested page renders restore the aggregate's anonymous context; the outer scope then restores the original caller even on failure. Preview and content cacheability vetoes propagate before the aggregate producer can publish a file; the HTTP handler returns a no-store plain-text 403. Page-level public publication follows §4.3 of the Markdown specification.

- A minimal site header first: `# {site name}` and the tagline blockquote (same as the index head), so the file identifies its origin.
- Then, for each post in `Core\Eligibility::enumerate( types_for('llms_full') )` (the same per-type order as the index), the page's Markdown via `Page_Markdown::materialise( Core\Markdown_Alternate::identity_for( $post ), $post )` — which serves the per-page cache file when warm and renders+caches it once when cold (the inner cache that softens the O(site) cold start, ADR-0007).
- **Separator** — each per-page `.md` opens with its `---` YAML front-matter, which is the natural record boundary; entries are joined with a blank line. An HR `---` separator is deliberately avoided because it collides with the front-matter fence.
- **Final string** — filter `kntnt_ai_visibility_llms_full_txt`.
- **Conversion failure** — one failed page conversion aborts the full producer before it can return successful aggregate bytes. No partial aggregate is cached; already completed public per-page files may be reused by the next attempt. The handler returns the deliberate non-cacheable `500` described in §5.2 of the Markdown specification and retries on a later cold request.
- Scope is `types_for('llms_full')` (§4.2), which **defaults to Pages only** (decision 6): a small site's full file is its cornerstone pages, and a large archive is never dumped wholesale unless the owner ticks those types into the `llms-full.txt` column.
- **Password-protected pages are absent** because `enumerate()` excludes them (§3.1), so `llms-full.txt` never concatenates protected content and the aggregation never writes a per-page `.md` cache file for a protected page — the early router can therefore never serve one. As belt-and-braces, the loop also skips any post whose `post_password` is non-empty.

### 4.5 Request handling and routing

**HTTP methods:** the early router and the slow request handler share the `GET`/`HEAD` policy. Every other method falls through to the ordinary WordPress workflow before provider matching, generation or conditional serving, whether `/llms.txt` or `/llms-full.txt` is cold or warm. The plugin emits no rejection or `Allow` header for this fall-through policy, does not populate the public artifact cache and does not issue a `304` for an unsupported method. This is the same policy as the Markdown alternate (§4.2 of its specification).

`Llms\Request_Handler` is the PHP path the early router falls through to on a cold or invalidated cache. It mirrors `Markdown\Request_Handler`:

- **Rewrite rules** (registered on `init`, flushed once on activation): `^llms\.txt$` and `^llms-full\.txt$`, each mapping to a marker query var (`index.php?kntnt_aiv_llms=index` / `=full`) so WordPress loads rather than 404s. Register `kntnt_aiv_llms` as a query var. Static and side-effect-only so `install.php` can register the same rules before flushing (parity with §4.2 of the Release-1 spec).
- **`template_redirect` (priority 0):** build the `Request` (`Request_Factory::from_globals()`), match it through the matching provider; on a match, materialise the aggregate through `Single_Flight::once( $identity, fn() => $provider->generate( $identity )->bytes )`, then serve the resulting cache file through `Serve_Router::headers_for()` — so this first serve and every later early-router serve agree on the validators (ETag from file md5, `Last-Modified` from mtime). A non-matching request is left to WordPress.
- There is no inline/negotiated form and no password gate — the singletons are site-wide public files, not per-post content. A request for a path that no provider matches simply falls through.

On the early path, `Plugin::serve_early()` already runs the router for every request; the new exact patterns make a warm `/llms.txt` or `/llms-full.txt` serve from the cache file and `exit` before WordPress routing (§3.4). The request handler only runs on a miss.

### 4.6 Response headers

On both the early-router path and the PHP path:

- `Content-Type: text/plain; charset=utf-8` (decision 7) — taken from the serve pattern.
- `Content-Length`, `Last-Modified`, `ETag` — and `304` on conditional requests (`If-None-Match`/`If-Modified-Since`), via the existing `Conditional_Request` logic.
- `X-Content-Type-Options: nosniff`.
- **No** `Link: rel="canonical"` (the singletons have no HTML canonical) and **no** `Vary` (no negotiation).
- `HEAD` is answered with headers and no body, like the `.md` path.

### 4.7 Discovery / advertising

Release 2 does not advertise the singletons (decision 8): no `<head>` `<link>`, no HTTP `Link` header. Discovery is by convention (`/llms.txt`). The `Discovery` walker from Release 1 only decorates singular pages and calls each provider's `advertise()`; the llms providers return `[]`, so they contribute nothing there. The llms.txt body does link to `/llms-full.txt` (§4.3), which is intra-artifact discovery, not HTTP advertising. Site-wide `Link`-header advertising is Release 3 (module c).

## 5. Caching and invalidation (ADR-0007)

1. **Same file cache, version-stamped keys.** The aggregates are stored by the Core `Store` under their kind and version-stamped key (`llms-txt/llms-v{N}.md`, `llms-full/llms-full-v{N}.md`). The on-disk `.md` extension is the store's universal convention; the served Content-Type is `text/plain` regardless (§3.4, decision 7).
2. **Lazy generation** on first request; no cron. A cold or post-bump request misses in the early router (no file for the current version), falls through, and the request handler generates, writes and serves.
3. **Invalidation = bump the cache-version stamp** (decision 3). `Llms\Invalidation` hooks `save_post`, `transition_post_status` and `deleted_post`. A save of a currently servable source, a transition from published status (including withdrawal) or permanent removal of a servable stored source advances the generation. The deletion hook uses the supplied old post even after WordPress removes its database row. The bump changes the version suffix, so the next request for either aggregate resolves to a key with no file → lazy rebuild; warmed index links and full-text bodies disappear promptly after removal. Revisions and autosaves are ignored (as in Release 1).
   - The per-page `.md` keys are **not** version-stamped, so a bump never invalidates them — bumping on a single post save rebuilds only the aggregates, not the whole per-page cache.
   - Indirect content and language changes are covered by `Markdown\Invalidation`. `Core\Content\Exposure_Invalidation` owns the unified exposure option lifecycle below. Both bump the version **and** flush the whole cache directory, including aggregates and per-page files.
   - A **TTL safety net** (the filterable `kntnt_ai_visibility_cache_ttl`) bounds staleness from changes no hook catches. The early router and the shared materialiser enforce the same request-selected lifetime for page, index and full files, including the freshness recheck under the flight lock. Age strictly greater than a positive TTL triggers lazy regeneration; non-positive TTL disables expiry consistently. See the complete lifetime and validator contract in [Markdown alternate §5](markdown-alternate.md#5-caching-and-invalidation-adr-0007).
4. **Single-flight** (§3.3) guards the O(site) `llms-full.txt` rebuild so concurrent cold misses do not all enumerate-and-concatenate.
5. **Stale-version files** (e.g. `llms-v7.md` after a bump to v8) are removed lazily in bounded batches after a persisted aggregate is captured for serving. `Store::prune_siblings( Identity $identity, callable $current ): void` evaluates the fresh authoritative identity callback under the shared short publication barrier. The caller must still match that identity and its current file must exist in a readable store. A stale caller, unavailable generation, failed persistence or poisoned store authorises no cleanup. Each invocation visits at most 32 direct directory entries and removes only regular, non-symlink files in the same owned aggregate key family whose numeric version is lower than the authoritative version. Current/newer generations, unknown names, the other aggregate kind and per-page directories are retained. Further successful materialisations drain an obsolete backlog without sorting or scanning the whole directory. This optional optimisation never renders content or waits for an entire response; TTL bounds serving age rather than physically deleting orphan files, while a whole-cache flush or the clear-cache button removes the full tree.

`Serve_Router::snapshot_for( string $path, bool $canonical = false ): ?Snapshot` captures the complete bytes and modification time through one opened, atomically published cache file. Optional canonical metadata is parsed from that same handle. `Snapshot` is readonly and supplies `bytes`, `last_modified` and the validated `canonical_url`. The singleton handler captures it before optional pruning, and both handlers and the early router derive headers through `headers_for_snapshot()` and emit those captured bytes. Subsequent pruning or atomic replacement of the pathname cannot change the response body, byte length, ETag or Last-Modified. GET, HEAD and conditional responses describe that same representation, including an old response which was validly published before the next generation completed. No lock survives capture or is held while sending the response. Capture failure in a materialising handler retains the generated-byte, no-store fallback; `persisted=false` never re-reads an older file or initiates pruning. The early router treats capture failure as a cache miss. Capturing full bytes uses memory proportional to the artifact size; there is no new eager rebuild.

The early router reads the cache version with a fresh database query **only** when an exact-versioned path matches — never on an ordinary request — so there is no per-request cost for the common case (§3.4). `Cache_Version::bump()` creates the structural option if absent and advances it with atomic SQL, independently of WordPress option caches. Production invalidation writers construct it with the cache store so version-only changes also revoke active work through the same barrier. Concurrent writers cannot collapse their increments.

An unavailable authoritative version SELECT is a refusal, not a missing option or generation one. Its early-router and WordPress singleton HTTP shells cover identity selection as well as materialisation and return the same plain-text, `no-store`, `nosniff` 403, with no validator or HEAD body. Cache-version SQL temporarily suppresses WordPress's visitor-facing database diagnostics and restores the exact previous suppression policy on success or failure. Dedicated-address classification uses the registered shape and safe key without selecting a cache generation; only actual serving needs the authoritative version.

For downstream cache work (#19 and #32), preserve the distinction between lifetime, stampede locking and publication validity. Expiry never authorises old bytes as a failure fallback. Optional sibling pruning must obtain and validate a current generation under this same barrier before deleting siblings; successful earlier publication alone does not authorise later pruning. Lock namespace changes must retain one stable store-scoped coordination inode and share it across all store objects and workers; injected test coordination directories must remain isolated and be cleaned only after their workers stop. Later invalidation writers (#14–#16) must use the store's coordinated delete/flush or a store-bound version bump rather than raw unlink, cache-tree deletion or cached read-modify-write counters.

Declared [public rendered metadata dependencies](markdown-alternate.md#43-generation-pipeline-core-page-markdown) use the same coordinated whole-cache turnover after native metadata writes. This removes non-versioned page artifacts and changes both aggregate identities, covering standalone API updates, final REST/field-writer values and featured-image changes without eager generation. Key-prefix declarations continue to match deleted repeater children. This generic writer contract does not certify an installed ACF package or a target theme; those integrations declare their actual field schema and retain separate site-specific verification.


## 6. Settings (Release 2 — the content-type matrix)

The scattered per-module post-type fields are replaced by **one Core-owned matrix** (ADR-0010: modules contribute, Core composes — here at column granularity). Under the single option `kntnt_ai_visibility`, namespaced `content_types`:

- **Rows** — every front-end-viewable, non-attachment post type (`Content_Types::rows()`).
- **Columns** — the capability columns the modules registered, in registration order: **Markdown (`.md`)**, **In `llms.txt`**, **In `llms-full.txt`** (§3.1). Releases 3–4 add columns by registering them, no rewrite.
- **Rendering** — a `<table>` of checkboxes named `kntnt_ai_visibility[content_types][{type}][{column}]`, server-side, no JS (ADR-0010).
- **The `.md` dependency** — the `llms.txt`/`llms-full.txt` cells require the row's `.md` cell. With no JS, this is enforced at **save** (the sanitiser forces a dependent cell off when its `.md` cell is off) and explained in the column help text — not by disabling controls in the browser.
- **Defaults** — `.md` on for every row, `llms.txt` on (inherits `.md`), `llms-full.txt` on for Pages only. Zero-config: an untouched install behaves exactly as decision 6 describes; saved cells only override.
- **Sanitiser** — walks the registered rows × columns (not the submitted shape), coerces each cell to a bool and applies the dependency, so injected keys cannot leak into the option.

### 6.1 Exposure option lifecycle

`Core\Content\Exposure_Invalidation` is the sole owner of artifact invalidation for `kntnt_ai_visibility` settings changes. It observes successful creation through WordPress's `added_option`, updates through `update_option_kntnt_ai_visibility` and successful removal through `deleted_option`. The generic add/delete callbacks act only on the plugin option. The post-deletion hook is intentional: WordPress's dynamic `delete_option_{name}` runs before the deletion, when the previous policy is still stored.

A first save is compared with absent, zero-config settings. A change to either `content_types` or `exclusions.paths` bumps the shared cache version once and flushes all plugin-owned files once, including when both slices change in one save. The comparison is conservative: any stored exposure-slice change triggers turnover; regeneration uses the shared effective `Eligibility`, aggregate selection and path-exclusion policies, including filters, without duplicating their policy logic in the lifecycle observer. Missing or malformed slices use the same defaults as the runtime readers. Changes confined to other sections, such as content signals, do not invalidate artifacts. Successful deletion always turns the cache over once, restoring the default policy; updating the option to an empty array also restores defaults and invalidates whenever a saved exposure slice changed. No settings option is eagerly created at bootstrap.

The Markdown module and the path-exclusion settings renderer register no overlapping lifecycle invalidation hooks. Before an option transition returns, all per-page cache files from the old policy have been removed and the aggregate generation has changed. A subsequent cold or warm request therefore uses the new shared exposure policy for dedicated Markdown, canonical query forms, discovery and both aggregate links and bodies. Code-driven filter deployments retain the separate purge procedure in §3.1; their options are not exposure-setting saves.

The **clear-cache action** moves to Core beside the matrix (it flushes the whole cache, which now includes the llms files); the Markdown and llms modules no longer register settings sections of their own — each contributes only its matrix column. Title and summary remain filter-only (`kntnt_ai_visibility_llms_title`/`_summary`); the site name and tagline are the zero-config defaults.

## 7. Activation / deactivation / uninstall

- **Activation (`install.php`):** also register the llms rewrite rules (`Llms\Request_Handler::register_rewrite_rules()`) before the single `flush_rewrite_rules()`, so `/llms.txt` and `/llms-full.txt` route on first activation alongside the `.md` rules.
- **Deactivation:** remove the llms module's owned in-memory rewrite entries and detach its `init` registration alongside the Markdown module before the shared flush, including a flush deferred until `wp_loaded`. Preserve unrelated owners' rules and the settings option, and clear the whole cache directory (covering both warmed aggregates). Reactivation restores both singleton routes. In plain mode the singletons use their exact-path PHP handler and WordPress's persisted rewrite set remains empty; deactivation returns unknown paths to native WordPress behaviour. The [Markdown lifecycle contract](markdown-alternate.md#7-activation--deactivation--uninstall) is per site and does not claim network-wide cleanup.
- **Uninstall:** removes the settings option, the cache-version option and the cache directory. The llms module introduces no new persistent state – it reuses the shared option, the cache directory and the cache-version stamp.

## 8. Testing strategy (ADR-0004)

- **Unit (Pest + Brain Monkey, WordPress mocked at the Core boundary):**
  - `Core\Content\Content_Types`: `is_enabled` (saved value, default closure, dependency forced off when `.md` is off), `types_for`, `rows`, `columns`; the matrix sanitiser (walks rows × columns, coerces bools, enforces the dependency) and the table renderer. `Core\Eligibility`: `is_servable` (status, viewability, attachment), `is_eligible` (servable AND has the `.md` capability), `enumerate` (query args and ordering — hierarchical vs date-descending). Model `get_post_types`/`is_post_type_viewable` to real WP behaviour (the Release-1 lesson: a sloppy `get_post_types` stub hid the pages-are-not-`publicly_queryable` bug). Assert the matrix defaults — `.md` all, `llms.txt` inherits `.md`, `llms-full.txt` Pages only — the `.md` dependency, and that `enumerate()` excludes drafts and **password-protected** posts (`has_password => false`).
  - `Core\Markdown_Alternate`: `identity_for`/`url_for` for home/`index`, nested pages, a dated permalink and a subdirectory base.
  - `Core\Cache\Single_Flight`: `once()` returns a cache hit without producing; produces, writes and returns on a miss; the lock path is exercised.
  - `Serve_Pattern` (`suffix`/`exact` constructors and facets) and **`Serve_Router` exact-path matching with the mandatory adversarial fixtures** (§3.4): version-stamped key derivation, per-pattern Content-Type, canonical suppression for singletons, and realpath containment holding against every traversal payload.
  - `Llms\Index_Builder`: H1, blockquote (present/omitted), intro with the llms-full reference, one section per type, section ordering, item format, `.md` links, excerpt descriptions, empty-excerpt omission, **Markdown-significant characters in a title escaped** (a `[`/`]`/backtick title cannot break the link), tags/shortcodes stripped from descriptions, the entry/sections/document filters.
  - `Llms\Full_Builder`: concatenation order, that it materialises through `Page_Markdown` (mocked) rather than re-rendering, the blank-line separator, the site header, the document filter, and that a **password-protected page is never concatenated nor cached**.
  - `Index_Provider`/`Full_Provider`: `match` (exact path → version-stamped identity; non-match → null), `generate` (Artifact with `text/plain`), `advertise` (`[]`), `serve_pattern` (exact).
  - `Llms\Request_Handler`: rewrite-rule registration, query-var registration, a matched request generating and serving via the router headers, a non-match falling through.
  - `Llms\Invalidation`: bump on an eligible save/transition, no bump for an ineligible or revision/autosave post, indirect-change flush still covered.
  - `Llms\Module` boot wiring (the `llms`/`llms_full` matrix columns registered, two providers registered, the handler and invalidation registered).
  - The refactor keeps the existing Markdown/Core suites green (the 163 baseline), updated to the moved seams.
- **Playground e2e (no DDEV fallback, ADR-0004; `--workers=1`, warm-path polling per the Release-1 pattern):** a real `GET /llms.txt` (200, `text/plain; charset=utf-8`, the H1, sections and `.md` links present); `GET /llms-full.txt` (200, `text/plain`, concatenated page content present, no second render); the early-router cache hit on a second request (file written on the first, served by the router on the second); a conditional request (`ETag` → `304`); `HEAD`; invalidation (publish/save a post → version bump → the next `/llms.txt` reflects it); the Pages-only `llms-full.txt` default (a published Page present, a published post absent until its column is ticked); a **password-protected published Page absent from both artifacts and its `.md` not cached** by the aggregation; the traversal payloads from §3.4 returning safe; and a subdirectory install resolving `/sub/llms.txt`. If Playground's php-wasm cannot exercise a behaviour, STOP and raise to the maintainer — never wire a DDEV fallback.
- **Coverage ≥ 80 %** (CI gate). `composer test && composer phpcs && composer stan` must be green; the Playground suite runs under `KNTNT_RUN_PLAYGROUND=1`.

## 9. Suggested build order (test-first)

Steps 1–4 extend or refactor Release-1 Core (and Markdown) code and must keep all existing tests green (red→green→refactor: behaviour preserved, tests moved to the new seam). Steps 5 onward are net-new llms classes.

1. **`Core\Content\Content_Types` + `Core\Eligibility`** — the matrix (columns/rows/`is_enabled`/`types_for`, sanitiser, table renderer) and the predicate/enumeration; register the `md` column from the Markdown module; replace the Markdown `post_types` field with the matrix; move the clear-cache action to Core; refactor the Markdown provider/discovery/invalidation onto `Core\Eligibility`; collapse `Markdown\Eligibility`. Keep the existing suites green.
2. **`Core\Markdown_Alternate`** — move `KIND`/`key_for`/`md_url`/`home_relative`; refactor the Markdown provider to delegate.
3. **`Core\Cache\Single_Flight`** — extract the lock-and-cache guard; refactor `Page_Markdown_Service::materialise`.
4. **`Serve_Pattern` + `Serve_Router` exact-path/versioned/Content-Type/canonical-flag** — adversarial tests first; wire the `cache_version` callback in `Plugin`.
5. **`Llms\Index_Builder`** (reads `types_for('llms')`).
6. **`Llms\Full_Builder`** (reads `types_for('llms_full')`; materialises through `Page_Markdown`).
7. **`Index_Provider` + `Full_Provider`** — and register the `llms`/`llms_full` columns in the llms module `boot()`.
8. **`Llms\Request_Handler`** (rewrite rules, serve) + `install.php` rule registration.
9. **`Llms\Invalidation`** (cache-version bump on eligible-post change).
10. **`Llms\Module`** wiring + the `Plugin` boot line.
11. **Playground e2e.**

## 10. Out of scope for Release 2

- RFC 8288 HTTP `Link`-header discovery and site-wide advertising of the singletons (module c, Release 3); `robots.txt` content signals and any robots.txt reference to llms.txt (module d, Release 4).
- Cron / scheduled pre-generation (lazy-on-request is the settled stance, ADR-0007); WP-CLI and REST endpoints; an "AI visibility score" dashboard or crawler analytics (Charter §Market: not needed for 1.0).
- A per-post inclusion/exclusion metabox — dropped by the maintainer as not worth the UI; curation is the type-level matrix, the per-URL **path-exclusion patterns** (§3.1, added post-Release-2) and the per-capability developer filters. A `## Optional` section in llms.txt is reachable through the `kntnt_ai_visibility_llms_sections`/`…_llms_txt` filters only.
- Pagination/comment-page and attachment handling (inherited Release-1 scope, §10 of the Release-1 spec).
- The non-ASCII-slug early-cache limitation (Release-1 deferred): `.md` links in llms.txt for non-ASCII slugs are valid URLs that resolve through the uncached PHP path, and `llms-full.txt` materialises such pages internally, so both work — the only gap is that those `.md` are not early-cached, which is the existing Release-1 limitation, not a new one.
- A webserver `try_files` tier for the singletons (left to the server owner, like the `.md` tier; it would need a `/llms.txt → text/plain` mapping).
- Trailing-slash normalisation for the singletons (`/llms.txt/`): agents request the exact path, so `/llms.txt/` simply falls through to a normal 404. A 301 to the canonical form, as the `.md` path does, is a trivial later addition if it proves needed.
- SEO `noindex` is not consulted: every eligible post is listed by default. Excluding `noindex`ed content is left to the `kntnt_ai_visibility_llms_post_types`/`…_llms_full_post_types` filters (and the per-entry filter), consistent with staying dependency-free (decision 5).
