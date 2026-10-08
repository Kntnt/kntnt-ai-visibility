# Spec 1.3 — Release 1: the Markdown alternate (Core slice + Markdown module)

This is the step-1.3 specification for **Release 1**: per-page Markdown alternates served by content negotiation. It turns the architectural seams of [`docs/architecture.md`](../architecture.md) and ADR-0006 … 0010 into concrete contracts an implementer can TDD against. Where this spec states *what*, the linked ADR states *why*; read the ADR when the reasoning matters. The behavioural reference is `Kntnt/markdown-alternate` (v1.2.0) — its behaviour, not its code.

Scope of this spec: the **Core slice Release 1 needs** (kernel, artifact registry, file cache + serve router, settings registry, logger, the shared Page-Markdown service) and the **Markdown-alternate module**. The Core seams are designed for their *known* roadmap consumers (the llms.txt module reuses Page-Markdown and the registry), per ADR-0006 — but only the Markdown module is implemented now.

## 1. Decisions (settled)

The six open forks have been settled by the maintainer; build to these.

1. **`Accept: text/markdown` → serve inline, uncached.** On the canonical URL, answer `Accept: text/markdown` by serving the Markdown inline (the uncached PHP path), emitting `Vary: Accept` and a `Link: <…>.md; rel="alternate"` that steers agents to the cache-grade `.md` URL. Not a 303 redirect.
2. **Discovery ships in Release 1.** Emit the per-page HTML `<link rel="alternate" type="text/markdown">` tag now (anti-regression — the plugin replaces `markdown-alternate`, which advertises this). The richer RFC 8288 HTTP `Link`-header discovery, site-wide over all artifacts, stays **module (c), Release 3** (ADR-0008).
3. **Absolutise URLs.** Pass the site domain to the converter so relative links and images become absolute, making the `.md` self-contained for an agent reading it out of context.
4. **Front-matter = parity + canonical URL.** Keys in order: `title, canonical_url, date, author, featured_image, categories, tags` — the reference shape plus `canonical_url` (the page's canonical HTML permalink, `get_permalink()`). `featured_image`/`categories`/`tags` stay conditional (omitted when empty).
5. **Title element vs H1 element — keep them distinct (see §4.3).** The HTML `<title>` element is page metadata, not shown in the page body, so it is **never injected into the Markdown body** — it lives only in the front-matter `title` key. The body still **leads with the page's visible H1** (the on-page heading), sourced from the post title, because that heading *is* part of the page a reader sees. Net: front-matter carries `title`; the body opens with `# {post title}` as the page's H1; the `<title>` element never enters the body.
6. **No `X-Markdown-Tokens` header in R1.** The reference's `strlen/4` estimate is a guess; omit it. Easy to add later if an agent tool needs it.

## 2. Scaffold reconciliation — fix during 1.4–1.5

The 1.1 scaffold predates ADR-0007/0010; three comments and two code facts are now stale and must be corrected as the slice lands:

- **Cache is files, not transients.** `install.php`, `uninstall.php` and `Plugin.php` describe the Markdown cache as transients (citing ADR-0001). ADR-0007 settled it as **files under the uploads cache directory**. `Plugin::deactivate()` and `uninstall.php` must clear the **cache directory** (and the cache-version option, §5.4), not the transient rows. The transient-deletion code can go once nothing uses transients.
- **Option key.** `uninstall.php` deletes `kntnt_ai_visibility_settings`; ADR-0010 fixes the single settings option at **`kntnt_ai_visibility`** (no suffix). Correct the `delete_option()` call.
- **Rewrite rules on activation.** `install.php` currently does nothing and says there are no rewrite rules. The Markdown module **registers rewrite rules and flushes once** on activation (§6.2); `install.php` is the wiring point, as its own comment anticipates.
- **Minor:** the main file header reads `Requires at least: 7.0`. WordPress is at 6.x — verify this is intended and not a typo (non-blocking).

## 3. Core slice — contracts

Namespaces follow the repo convention (`Kntnt\Ai_Visibility\…`, `Pascal_Snake_Case` classes, PSR-4 under `classes/`). The signatures below are the **committed seams** — designed as if they cannot change (ADR-0006) — refined in detail during TDD.

### 3.1 The module boot contract (`Plugin` ↔ Module)

`Plugin` sees a module only through this thin contract and boots modules in a fixed dependency order. Release 1 has one module; the contract still carries ordering for the roadmap.

```php
namespace Kntnt\Ai_Visibility\Core;

interface Module {
    // Booted once by Plugin, in dependency order, after Core services exist.
    // The module registers its artifact provider(s), settings section and hooks.
    public function boot( Core $core ): void;
}
```

`Core` is a narrow facade exposing the Core services a module may use — `artifacts(): Artifact_Registry`, `settings(): Settings_Registry`, `page_markdown(): Page_Markdown`, `logger(): Logger`. Modules depend on these abstractions (DIP); they never touch each other (ADR-0006, seam tier 3).

`Plugin::__construct()` instantiates Core, then instantiates and boots each module in order. The wiring seam in `Plugin.php` (today: only `Updater`) gains the Core construction and the Markdown module boot.

### 3.2 Artifact identity and provider (ADR-0008)

The **discoverable artifact** is the central abstraction (`CONTEXT.md`). A provider is a *rule, not an enumeration*: one provider covers every eligible page.

```php
namespace Kntnt\Ai_Visibility\Core\Artifact;

// Value object: one concrete artifact instance. `key` is the stable,
// path-safe cache key Core derives the cache filename from — never the raw URL.
final readonly class Identity {
    public function __construct(
        public string $kind,        // e.g. 'markdown-alternate'
        public string $key,         // stable, validated, path-safe
        public int $source_id = 0,  // e.g. the post ID; 0 for singletons
    ) {}
}

interface Provider {
    // MATCH: does this provider own the current request? Identity or null.
    public function match( Request $request ): ?Identity;

    // GENERATE: produce the artifact for an identity (bytes + content type +
    // last-modified). Pure of HTTP; the router/serve layer emits headers.
    public function generate( Identity $identity ): Artifact;

    // ADVERTISE: link relations this provider contributes to a given HTML page
    // (Release 1: the page's own `.md` alternate). Consumed by module (c).
    public function advertise( Discovery_Context $context ): array; // Link_Relation[]

    // The request shape this provider serves — feeds the router allowlist.
    public function serve_pattern(): Serve_Pattern;
}
```

`Artifact` carries `bytes(): string`, `content_type(): string` (`text/markdown; charset=utf-8`) and `last_modified(): int`.

### 3.3 The artifact registry (ADR-0008)

One registry, three internal **ISP slices** over a single source of truth (the registry stays deep externally; the slices never surface on the provider interface):

```php
namespace Kntnt\Ai_Visibility\Core\Artifact;

interface Registry {
    public function register( Provider $provider ): void;
}
// Internal views Core consumes:
//   Serving_View   — match + generate, for the serve router
//   Discovery_View — advertise, for module (c)
//   Allowlist      — serve patterns, for router security
```

### 3.4 File cache and the early serve router (ADR-0007)

One file is both the inner cache (skip render+convert) and the outer cache (skip the WordPress lifecycle). The router is the hardened, adversarially-tested heart of the plugin.

```php
namespace Kntnt\Ai_Visibility\Core\Cache;

interface Store {
    public function path_for( Identity $identity ): string; // contained cache path
    public function has( Identity $identity ): bool;
    public function read( Identity $identity ): ?string;
    public function write( Identity $identity, string $bytes ): bool; // atomic publication succeeded
    public function delete( Identity $identity ): void;
    public function flush_all(): void;          // clears the whole cache dir
}
```

- **Location:** an isolated dir Core alone writes — `wp_upload_dir()['basedir'] . '/kntnt-ai-visibility-cache/'`. Protect it from listing (an `index.html`/deny rule); the webserver may serve `.md` files directly from here via `try_files` (it needs a `.md → text/markdown` mime mapping), but that tier is the server owner's, not ours.
- **Early serve algorithm** (runs as early as a plugin can — from the main file after the autoloader, before WordPress routing):
  1. Act only on a GET/HEAD whose path matches a registered artifact shape (ends `.md`, or a known singleton path). Otherwise return and let WordPress proceed.
  2. Derive a safe key from the **path only** (query stripped). Validate against a strict whitelist regex; **reject** `..`, encoded traversal (`%2e`, `%2f`, `\`), null bytes, anything outside the allowed character class. Core fixes the base dir and the `.md` extension itself — never reflect the raw URL.
  3. `realpath()` the candidate and assert it is **strictly inside** `realpath(base_dir)` (defeats traversal and symlink escape). Reject otherwise.
  4. Optionally assert the derived shape is in the registry allowlist before serving.
  5. On a hit: emit `Content-Type`, `Content-Length`, `Last-Modified`, `ETag`; answer `If-None-Match`/`If-Modified-Since` with `304`; `readfile`; `exit`.
  6. On a miss: return. WordPress proceeds; the matching provider lazily generates, writes the file (§5) and serves it.
- **Security is non-negotiable:** path-traversal payloads (`../`, encoded, null-byte, absolute paths, symlinks) are a **mandatory test fixture**. Page-cache plugins have shipped CVEs on exactly this pattern.

### 3.5 The shared Page-Markdown service

The render → convert → front-matter → assemble → cache pipeline is a **Core service**, not module-private, because the llms.txt module (Release 2) concatenates the same per-page Markdown into `llms-full.txt` — never a second HTML render (ADR-0007, `CONTEXT.md`). Designing it as a Core seam now is the committed-roadmap foresight of ADR-0006.

```php
namespace Kntnt\Ai_Visibility\Core;

interface Page_Markdown {
    // The page's Markdown body+front-matter (used directly by the Markdown
    // module; concatenated by the llms.txt module). Renders, converts, builds
    // front-matter, assembles. Pure of HTTP and caching.
    public function for_post( \WP_Post $post ): string;

    // Materialise with single-flight; valid bytes are independent of persistence.
    public function materialise( Identity $identity, \WP_Post $post ): Cache\Materialisation;
}
```

### 3.6 Settings registry and the logger (ADR-0010)

```php
namespace Kntnt\Ai_Visibility\Core\Settings;

interface Registry {
    // A module contributes one section (fields, defaults, sanitisers); Core
    // composes ONE server-side settings page (no JS), one section per module.
    public function register_section( Section $section ): void;
}
```

- **One option, `kntnt_ai_visibility`** (array; modules namespace their keys within it). Zero-config: defaults live in code and *work untouched*; settings only override. Developer filters mirror each setting as the programmatic escape hatch (e.g. post-type eligibility).
- **Logger:** a minimal interface (`error`/`warning`/`info`/`debug` with a context array), silent toward visitors — diagnostics go to a plugin log / `error_log()`.

## 4. The Markdown-alternate module

The module is thin: it registers a `Page_Markdown_Provider` and a settings section, hooks rewrite rules and discovery, and delegates the actual conversion to the Core `Page_Markdown` service.

### 4.1 Eligibility and scope (ADR-0009)

A request resolves to a Markdown alternate iff it resolves to a **single, public, published** entry:

- **Post type:** every **front-end-viewable** post type by default — `is_post_type_viewable()`, the same predicate as the eligibility hard guard, so the default set is exactly what can pass. This deliberately keys on viewability rather than `publicly_queryable`: the built-in `page` type is public and viewable but **not** `publicly_queryable`, so a `publicly_queryable`-keyed default would exclude pages and break the per-page feature (the reference hard-coded `post, page`). The default is therefore posts, pages and any publicly-queryable CPT, minus attachments — not an allow-list. Overridable via a settings field and a filter.
- **Status:** `publish` only. Drafts, pending, private, scheduled → no `.md` (404 / fall through).
- **Password-protected:** `403` with a plain-text body (e.g. `This content is password protected.`).
- **Home:** `/index.md` **iff the front page is a static page** (`show_on_front === 'page'`); both this dedicated path and canonical-home negotiation resolve only `page_on_front` in the source language. An ordinary page named `index` never substitutes for a static or blog home.
- **Excluded by default:** blog index, date/taxonomy/author archives, search, attachments — they are listings, not singular content. Widening to archives/taxonomies is a later **opt-in** settings field (default off). Pagination (`/page/N/`) and comment pages are **not** handled — the whole post renders as one `.md` unit.

### 4.2 Request forms, routing and precedence (ADR-0009)

Four reachable forms; strict precedence **`.md` URL > `?format=markdown` > `Accept`**:

1. **`.md` suffix** on a slugged URL (`/about/team.md`, `/category/news.md` for a singular post under that path) – the cache-grade, advertised path. An ordinary canonical path ending in `/index/` appends `/index.md` instead: `/index/` advertises `/index/index.md` and `/index/index/` advertises `/index/index/index.md`. Their home-relative keys append the same extra `index` segment, keeping every ordinary index leaf distinct from the home and from deeper index pages. The provider reverses exactly one segment only for dedicated `.md` requests and refuses shorter aliases. Canonical `/index/` negotiation still resolves the ordinary page. **Lowercase `.md` only**; uppercase/mixed → not matched (404).
2. **`?format=markdown`** on the canonical URL — same provider/cache as the `.md` path.
3. **`Accept: text/markdown`** on the canonical URL — the standards-correct form. Default (§1.1): serve Markdown **inline, uncached**, with `Vary: Accept` and a `Link: <…>.md; rel="alternate"` steering agents to the cache-grade URL. Select Markdown only when an explicit `text/markdown` or `text/x-markdown` range has positive quality strictly above the HTML alternative; HTML wins ties. Evaluate `text/html` and `application/xhtml+xml` using their most specific matching range (exact type, then `text/*` or `application/*`, then `*/*`), retaining explicit `q=0`. Wildcards alone never select Markdown. Media types and `q` names are case-insensitive; trim surrounding whitespace. A quality value outside RFC 9110's 0–1 grammar, more than three fractional digits, a missing value or repeated `q` parameters gives that range zero quality. These rules do not change `.md` or `?format=markdown` precedence.
4. **`/index.md`** reserved for the static home; a blog home has no per-page alternate. Translated homes keep their source-language prefix and subdirectory installations keep their configured base.

**HTTP methods:** only `GET` and `HEAD` enter artifact handling. Every other method falls through to the ordinary WordPress workflow before negotiation, trailing-slash redirects, generation or conditional serving. This applies to canonical URLs (including `?format=markdown` and Markdown in `Accept`) and dedicated `.md` paths alike, on cold and warm caches. The plugin does not emit a rejection or an `Allow` header for this fall-through policy; downstream page and form handlers retain control of the response. Unsupported methods never materialise an artifact or receive a plugin-generated `304`.

Mechanics, parity with the reference:

- **Rewrite rules** (registered on `init`, flushed once on activation): `^index\.md$` and the non-greedy `(.+?)\.md$`, mapping to an internal query var (`markdown_request=1`) plus the resolved page. Register `markdown_request`/`format` as query vars.
- **Resolution:** reconstruct the path and resolve via `url_to_postid()`, trying the common permalink extensions then bare — this handles nested pages and date permalinks (`/2024/01/slug.md`) for free, no bespoke parsing.
- **Non-ASCII slugs are load-bearing:** read the path with `esc_url_raw( wp_unslash( … ) )`, **never** `sanitize_text_field()` (which strips `%`-octets and destroys å/ä/ö slugs). Preserve percent-encoding; hand it straight to `url_to_postid()`.
- **Canonical-redirect suppression:** return `false` from `redirect_canonical` when serving a `.md`, so WordPress does not mangle the URL.
- **Trailing slash:** `/slug.md/` → `301` → `/slug.md`.

### 4.3 Generation pipeline (Core Page-Markdown)

1. **Render** the content: `apply_filters( 'the_content', $post->post_content )` (renders shortcodes and blocks). Input to the converter is the **content only**, never a full themed page — so no nav/header/footer stripping is needed. Ensure UTF-8 (decode first if ever not).

   Public artifact rendering uses WordPress's anonymous user (`wp_set_current_user(0)`), with cookies, query/form/request data and HTTP authentication credentials hidden. Existing WordPress query objects are cloned with their request query data removed, preserving main-query identity when both globals originally referred to the same query. Content and front-matter filters see this same audience. Caller identity, user globals, query objects, mutable request data and response headers are restored in `finally`, including when a content integration throws. Preview requests (query flags or WordPress preview state) cannot render or materialise public artifacts: autosave content and preview metadata must never enter shared files. The stored-password exclusion applies before rendering and before warm-cache reads. Each page pipeline additionally enters the source context below inside this anonymous audience scope.

   Public publication fails closed when an integration sets `DONOTCACHEPAGE`, invokes `nocache_headers()` or `litespeed_control_set_nocache` during rendering, emits a new private/no-store/no-cache response policy or sets a cookie. An existing `DONOTCACHEPAGE` veto also prevents materialisation and aggregate generation. Its immutable PHP constant stays set; mutable scope observers, request state and header changes are restored. The negotiated inline path's pre-existing transport bypass does not itself invalidate an anonymous, uncached render. HTTP denial is a plain-text 403 with no-store; no per-page or aggregate artifact is written from the refused producer. Integrations that retain their own visitor-specific state must signal this veto rather than publish those bytes as public content.

   **Supported source context:** `Page_Markdown::for_post()` runs the complete content, conversion, title and front-matter pipeline inside `Post_Context`. The supplied source is the global post and queried object. An isolated, one-post `WP_Query` is both `$wp_query` and `$wp_the_query`, so `get_queried_object_id()`, `get_the_ID()`, `is_singular($post_type)` and `is_main_query()` describe that source and `in_the_loop()` is true when content integrations begin. Page sources have the page conditional; other supported singular post types have the single conditional. The query contains only source identity/type and WordPress query defaults, with no visitor query variables. Core parses it and enters its Loop with WordPress APIs using the supplied post rather than executing a second source database query. An explicitly supplied `page_for_posts` source is also rendered as singular content; the blog index remains excluded by the routing contract in §3. This is a content-rendering context; Core does not run the canonical page's template or HTTP request lifecycle or promise every canonical request flag.

   For a Bogo source, Core switches to `bogo_get_post_locale($post->ID)` with WordPress's locale switcher before establishing the source query. Shortcodes, dynamic blocks, field-backed content filters and metadata filters therefore share the source and locale, including when a source-less `llms-full.txt` request renders translated A/B sources in succession. Within `finally`, Core restores the presence and exact values of the caller's post-data globals, original query object identities and alias relationships, Loop state and previous locale on success or any exception. The caller's query objects are never rewound or re-queried. Nested source rendering restores its enclosing context; the outer anonymous scope subsequently restores the original visitor. Public-content adapters and relative-reference handling consume this same scope rather than rebuilding request context themselves.

2. **Convert** with `kntnt/html-to-markdown` — the **full `Converter`** (`BasePlugin` + `CommonmarkPlugin` + `TablePlugin` + `StrikethroughPlugin`) for full GFM; the `HtmlToMarkdown::convert()` facade omits tables and strikethrough. Pass `Options( domain: site_url )` so relative links/images absolutise (§1.3). The converter is an **internal collaborator**, not a public seam, and is **not a sanitizer** — acceptable here because the input is our own rendered content, not untrusted HTML.
3. **Front-matter** (YAML between `---` fences), keys in order (decision 4):
   - `title` — quoted; `get_the_title()`, entities decoded. Page **metadata**; never re-emitted into the body (decision 5).
   - `canonical_url` — quoted; the page's canonical HTML permalink (`get_permalink()`).
   - `date` — unquoted; `Y-m-d`.
   - `author` — quoted; the author's `display_name`.
   - `featured_image` — quoted URL; **omitted when absent**.
   - `categories` — list of `{ name, url }` where `url` is the term's existing HTML archive from `get_term_link()`, preserving its path, trailing slash and encoded query parameters. No `.md` suffix is added because taxonomy archives have no Markdown provider (§4.1). Term names and URLs are escaped as double-quoted YAML scalars, preserving Unicode. If a term URL lookup returns `WP_Error`, retain the name with an empty URL; if the term-list lookup fails or is empty, omit that taxonomy field.
   - `tags` — same shape; omitted when empty.
   - Filterable before serialisation (taxonomy→key map, content lines, raw lines), mirroring the reference's `…_frontmatter_*` filters.
4. **Assemble:** front-matter, a blank line, then the body. The body **leads with the page's visible H1** — `# {get_the_title()}` — because the on-page heading is part of what a reader sees, and standard WordPress renders the title via the theme *outside* `the_content` (so it is absent from the converted output). This H1 is the **visible heading**, distinct from the `<title>` element, which is metadata and lives only in the front-matter `title` key (decision 5) — the `<title>` element is never injected into the body. A blank line follows, then the converted body. (If a setup already emits the title heading inside `the_content`, de-duplicating the leading H1 is a later refinement.)

### 4.4 Response headers

On both the cache-serve path (router) and the PHP path:

- `Content-Type: text/markdown; charset=utf-8` — essential; without it the bytes are mislabelled as HTML.
- `Content-Length`, `Last-Modified`, `ETag` — and `304` on conditional requests.
- `Link: <canonical-HTML-URL>; rel="canonical"` — the `.md` points back at its HTML canonical (avoids duplicate-content confusion). The HTML stays `rel="canonical"`; the `.md` is `rel="alternate"`.
- `X-Content-Type-Options: nosniff`.
- `Vary: Accept` on the negotiated (`Accept`) path and canonical HTML; combine existing values case-insensitively and retain an existing `*`.
- `Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate` on negotiated 200 and 304 responses. Establish WordPress/cache-plugin bypass signals before page-cache integration initialisation. Dedicated `.md` addresses retain their cache-grade policy. Caches serving before WordPress require explicit configuration and already polluted caches must be purged; follow the [target-site verification procedure](../operations/negotiated-cache.md).

### 4.5 Discovery in Release 1

- The module emits the per-page HTML tag on `wp_head` for eligible singular published pages: `<link rel="alternate" type="text/markdown" href="…md">` (§1.2, anti-regression vs the replaced plugin).
- The provider's `advertise()` exposes the same relation as data, so module (c) (Release 3) can render it — and the site-wide artifacts — into RFC 8288 HTTP `Link` headers. No HTTP `Link: rel="alternate"` header in Release 1.

## 5. Caching and invalidation (ADR-0007)

1. **Files under uploads**, Core-owned (§3.4). One file per page `.md`.
2. **Lazy generation** on first request; no cron pre-render.
3. **Only public, published content is cached** — the early serve runs before WP auth, so a cached file must never represent non-public content.
4. **Invalidation = delete-on-change:**
   - A page's own `.md` is **deleted on save and on status transition** (publish→draft/trash/private). Per-entity regeneration is immediate after the editor's response (keep the save fast); aggregate artifacts regenerate lazily (Release 2 concern).
   - Indirect changes (theme, menus, translations) bump a **cache-version stamp** held in its *own* option key (per ADR-0010's note — not a key inside the settings array). Core owns [exposure-setting creation, updates and removal](llms-txt.md#61-exposure-option-lifecycle): one version bump and whole-cache flush per relevant option transition, including a first matrix-only save from an absent option and restoration of zero-config defaults.
   - A **TTL safety net** (filterable) bounds staleness from changes no hook catches.
5. **Single-flight:** lazy regeneration has a stampede risk — guard generation with a per-identity lock (lock file or short-lived transient) so concurrent misses do not all render. Required for correctness under load.

### 5.1 Unavailable persistence

`Store::write( Identity, string ): bool` returns `true` only after the complete bytes have been atomically renamed into place. Directory creation, listing-guard creation, short or failed writes and failed renames return `false` with a diagnostic through the plugin logger; filesystem warnings do not reach visitors. A partial write or failed rename removes its temporary file. The supplied bytes remain valid when storage is unavailable.

`Single_Flight::once()` and `Page_Markdown::materialise()` return `Core\Cache\Materialisation`, a readonly value with `bytes: string` and `persisted: bool`. A fresh cache hit or a successful atomic write sets `persisted=true`. A failed write returns the current generated bytes with `persisted=false`, even when a readable but expired older file remains. Consumers must use that outcome rather than infer publication from file existence. The llms full builder concatenates `bytes` regardless of per-page persistence. Producer exceptions propagate without publication or a materialisation result; an eligibility refusal or generation failure must never be reclassified as a storage outage or substitute stale bytes.

On GET and HEAD, a handler with valid bytes but unavailable persistence serves those bytes directly through `Serve_Router::headers_for_bytes()`. The status is `200`, `Content-Length` is the generated byte count and `ETag` is the generated content hash. The response retains the artifact's Content-Type, `nosniff` and any canonical link. `Last-Modified` uses the post's GMT modification time for a Markdown alternate and the build time for an aggregate. `If-None-Match` and `If-Modified-Since` retain their ordinary precedence and may produce a bodyless `304`; HEAD is also bodyless. Both statuses send `Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate`. A file disappearing after successful materialisation uses the same generated-byte fallback. Neither storage failure nor file disappearance may fall through to successful HTML. The GET/HEAD and unsupported-method policy in §4.2 still applies before any generation.

### 5.2 Conversion failure and recovery

`Page_Markdown::for_post()` and `materialise()` throw `Core\Markdown_Conversion_Failed` when the conversion stage fails. The service records the underlying diagnostic through `Logger` and never assembles or publishes a metadata/title-only success for that failure. This differs from a successful empty converted body, which still produces and may cache its normal front-matter and visible H1, and from unavailable persistence, which returns valid `Materialisation` bytes with `persisted=false` (§5.1).

A fresh valid artifact is reused without conversion. Once generation is required, a conversion failure does not substitute expired bytes or produce a materialisation result. The next request retries the same identity; no manual cache purge is needed to recover. The failed producer writes no new page artifact. A full aggregate fails as a whole when one constituent conversion fails: previously completed public per-page artifacts may remain reusable, but no truncated successful aggregate is published.

The dedicated, query-form and negotiated inline HTTP paths, and the full aggregate handler, return a fixed plain-text `500` with `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`. The underlying exception and diagnostic never enter the response. HEAD has no body; conditional headers cannot turn a failed conversion into `304`. Public-source refusals retain their separate `403` policy. Unsupported methods still fall through before generation (§4.2).

## 6. Settings (Release 1 Markdown section)

The framework is not an empty shell — the Markdown module contributes a real section (ADR-0010):

- **Post-type override** — default = all front-end-viewable types (`is_post_type_viewable()`, which includes pages); let the owner narrow/extend it.
- **Archives/taxonomies opt-in** — default **off**; turning it on widens scope beyond singular content (the provider gains those identities).
- **Clear-cache action** — a button that calls `Store::flush_all()` / bumps the cache-version.

All under `kntnt_ai_visibility`; every field mirrored by a filter.

## 7. Activation / deactivation / uninstall

- **Activation (`install.php`):** register the Markdown rewrite rules, then `flush_rewrite_rules()` once.
- **Deactivation (`Plugin::deactivate()`):** flush rewrite rules; **clear the cache directory**; preserve the settings option.
- **Uninstall (`uninstall.php`):** delete the `kntnt_ai_visibility` option **and** the cache-version option, and remove the cache directory. Drop the stale `kntnt_ai_visibility_settings` deletion and the transient sweep once nothing writes them.

## 8. Testing strategy (ADR-0004)

- **Unit (Pest + Brain Monkey, WordPress mocked at the Core boundary):** provider `match`/`generate`/`advertise`; registry and its slices; **cache path derivation and the serve router with mandatory adversarial path-traversal fixtures**; front-matter builder (each key, conditionals, ordering); the converter wrapper (tables/strikethrough present, URLs absolutised, UTF-8); settings registry composition and defaults; eligibility (post type, status, password, home).
- **Playground e2e (no DDEV fallback):** a real `.md` request (200, correct headers, body), `?format=markdown`, `Accept` negotiation (`Vary`, the steering `Link`), `/index.md`, a 404 for a non-eligible URL, a 403 for password-protected, a 301 for the trailing slash, and traversal payloads returning safe.
- **Coverage ≥ 80 %** (CI gate).

## 9. Suggested build order for 1.4–1.5 (test-first)

1. `Module` contract + `Core` facade + `Plugin` wiring (boot ordering). 2. `Logger`. 3. `Settings\Registry` + the one option + one page (minimal Markdown section). 4. `Artifact\Identity` + `Provider` + `Registry` (+ ISP views). 5. **`Cache\Store` + the early serve router — adversarial tests first.** 6. Markdown eligibility/`match` + rewrite rules + `url_to_postid` resolution. 7. `Page_Markdown` (render → convert → front-matter → assemble) + cache write. 8. Serve headers + content negotiation + canonical suppression + trailing-slash. 9. Discovery (`wp_head` `<link>`) + invalidation hooks (save, status transitions, cache-version). 10. Playground e2e.

## 10. Out of scope for Release 1

llms.txt / llms-full.txt (Release 2); RFC 8288 HTTP `Link`-header discovery and site-wide advertising (module c, Release 3); `robots.txt` content signals (Release 4); pagination/comment-page alternates; attachment alternates; a webserver `try_files` tier (left to the server owner). The Core seams (registry, Page-Markdown, settings) are built to receive Releases 2–4 without breaking changes, but those modules are not implemented now.
