# Architecture

This is the orienting document for Kntnt AI Visibility: how the plugin is
structured and why the pieces fit the way they do. It is a synthesis – the
binding decisions and their rationale live in the ADRs under
[`docs/adr/`](adr/), and the domain vocabulary lives in
[`CONTEXT.md`](../CONTEXT.md). Where this document states *what*, the linked
ADR states *why*; read the ADR when the reasoning matters.

It reflects the design settled through step 1.2 of the
[Charter](Charter.md) plan. Concrete contracts (method signatures, the
Markdown front-matter shape, cache mechanism details, settings fields) are
the province of the spec (step 1.3) and the code; this document deliberately
stops at the architectural seam.

## The product, in one paragraph

Kntnt AI Visibility makes a content site discoverable, readable and usable by
AI agents, with zero configuration and no dependency on an SEO or e-commerce
plugin. It ships four out-of-the-box features: a Markdown alternate of each
page via content negotiation, auto-generated `llms.txt` + `llms-full.txt`,
RFC 8288 Link-header discovery and content signals in `robots.txt`.

## Shape: a Core plus four deep modules

The plugin is a **Core** plus **four feature modules**, wired by the `Plugin`
singleton – the lightweight house pattern, not a runtime module framework
(see [ADR-0006](adr/0006-deep-module-architecture.md)).

| Module | Feature | Release |
|---|---|---|
| Markdown alternate | per-page Markdown via content negotiation | 1 |
| llms.txt | `llms.txt` + `llms-full.txt` | 2 |
| Link headers | RFC 8288 agent-discovery headers | 3 |
| Content Signals | AI-usage directives in `robots.txt` | 4 |

Each module is a **deep module**: a narrow, stable external interface hiding
substantial work. The narrow interface is the seam at which the module is
tested and mocked – the primary quality metric for the boundary. SOLID governs
the *internals*; ISP decomposition never surfaces on the external interface,
which stays deep.

### The three seam tiers

1. **`Plugin` ↔ Module.** `Plugin` sees a module only through the thin `Module`
   boot contract (`boot()` + declared ordering); it knows nothing of internals.
2. **Module ↔ Core service.** Core exposes shared services behind narrow
   interfaces; modules depend on those abstractions and receive them injected
   (DIP).
3. **Module ↔ Module.** Modules never reach into each other. Cross-module
   coupling – module (c) advertising what (a) and (b) produce – goes through a
   Core-owned registry: producers publish, consumers read.

### Design balance

YAGNI/KISS/DRY are the default and kill *speculation* (which is why there is no
runtime module-toggle system and no third-party module API). But the four
modules and their documented couplings are **committed requirements**, so the
Core seams are designed now for their *known* set of consumers – because "the
external interface is a commitment; design it as if it cannot be changed." We
design the known-roadmap seams up front; we do not *implement* modules ahead of
their release. See [ADR-0006](adr/0006-deep-module-architecture.md).

## The artifact model

The central abstraction is the **discoverable artifact**: a non-HTML
representation the plugin exposes at its own URL for AI agents – a page's
**Markdown alternate**, `llms.txt` or `llms-full.txt`
([`CONTEXT.md`](../CONTEXT.md)).

A module contributes artifacts by registering an **artifact provider** with the
Core registry. A provider is a deep object with three responsibilities:

- **match** a request → an artifact identity (or nothing) – also the security
  pattern and the serve allowlist;
- **generate** the identity → the artifact's bytes;
- **advertise** itself, given a discovery context → link relations.

A provider is a *rule, not an enumeration*: one Markdown-alternate provider
covers every eligible page; `llms.txt`/`llms-full.txt` are singleton providers.
The registry is the single source of truth read by three consumers – the serve
router, module (c) discovery and the security allowlist – exposed through
focused interface slices so it never becomes a god-object. See
[ADR-0008](adr/0008-artifact-provider-registry.md).

## Core services

- **Kernel** – the `Plugin` singleton, the `Module` boot contract, and
  dependency-ordered boot.
- **Page-Markdown provider** – renders a page to HTML, converts it to Markdown
  (the `kntnt/html-to-markdown` converter is an *internal* collaborator, not a
  public seam) and caches the result. Shared by the Markdown module (serves it)
  and the llms.txt module (concatenates it into `llms-full.txt`).
- **Artifact-provider registry** – described above.
- **File cache + early serve router** – described below.
- **Settings registry** – modules register settings sections; Core composes one
  server-side settings page. One option, `kntnt_ai_visibility`. See
  [ADR-0010](adr/0010-zero-config-settings-registry.md).
- **Logger** – plugin-managed diagnostics; silent toward visitors.

## The request lifecycle

**A Markdown-artifact request** (`/about.md`, `/index.md`, `?format=markdown`):

1. As early in the WordPress bootstrap as a plugin can hook, the **serve router**
   maps the request to a safe, contained cache path. If a valid cache file
   exists, it emits the headers and the file and `exit`s – the WordPress request
   lifecycle is skipped.
2. On a miss, WordPress proceeds; the matching provider renders + converts the
   page, **writes the cache file** and serves it. The file is simultaneously the
   inner cache (skip render+convert) and the outer cache (skip bootstrap next
   time).

**An HTML request** is never short-circuited, so module (c) decorates it through
normal hooks – adding `Link: <…>.md; rel="alternate"; type="text/markdown"` and
advertising site-wide artifacts.

See [ADR-0007](adr/0007-file-cached-artifacts-early-contained-router.md).

### Caching

Markdown artifacts are cached **as files** under the uploads directory, owned by
Core. Generation is **lazy** (on first request; no cron pre-render). Only
**public, published** content is cached, since the early serve runs before WP
auth – status transitions invalidate. Invalidation is delete-on-change: a
per-entity artifact (a page's own `.md`) regenerates immediately after the
editor's response; aggregate artifacts (`llms.txt`, `llms-full.txt`) and
bulk/global changes regenerate lazily, because their cost is O(site).

The earliest plugin hook is the built-in default; a webserver `try_files` tier
(zero PHP) is left to whoever owns the server – the files are already on disk for
it. No `advanced-cache.php` drop-in.

### Router security

The serve router is hardened, adversarially-tested code – page-cache plugins have
shipped path-traversal CVEs on this exact pattern. It whitelists the request
shape, derives a safe key (fixing the base dir and `.md` extension itself, never
reflecting the raw URL), `realpath`-contains the result inside the cache base,
serves only from an isolated cache dir Core alone writes and ideally serves only
artifacts present in the registry allowlist. Traversal payloads are a mandatory
test fixture.

## The Markdown alternate

A page's Markdown alternate is reachable four ways
([ADR-0009](adr/0009-markdown-alternate-serving-strategy.md)):

1. **`.md` suffix** on a slugged URL – the cache-grade, advertised path.
2. **`?format=markdown`** – fallback, same provider/cache.
3. **`Accept: text/markdown`** on the canonical URL – the standards-correct form,
   but the *uncached* PHP path, emitting `Vary: Accept` and a `rel="alternate"`
   Link that steers agents to the cacheable URL.
4. **`/index.md`** for the slug-less root/home.

The HTML stays `rel="canonical"`; the `.md` is advertised as `rel="alternate"`
and carries `rel="canonical"` back, avoiding duplicate-content confusion.

**Default scope:** every singular entry of a `publicly_queryable` post type gets
an alternate; the home gets `/index.md` if and only if the front page is a static
page; blog index, archives and search get none by default (widening is a later
opt-in).

## Configuration

Zero-config: the defaults work untouched, and settings only *override* them. Each
module registers a settings section with the Core settings registry, which
composes one server-side settings page (no JS). All settings live in a single
`kntnt_ai_visibility` option. Site owners get the UI; developers keep filters as
the programmatic escape hatch. See
[ADR-0010](adr/0010-zero-config-settings-registry.md).

## Runtime foundation

- **PHP 8.5 floor**, inherited from the bundled converter
  ([ADR-0001](adr/0001-php-8-5-floor.md)); owner-controllable to 8.4 if needed.
- **`kntnt/html-to-markdown`** bundled unscoped in `vendor/`
  ([ADR-0002](adr/0002-vendor-converter-unscoped.md)).
- **GitHub distribution** with a self-update `Updater`
  ([ADR-0003](adr/0003-github-is-the-1-0-distribution-channel.md)); releases are
  automated on version tags
  ([ADR-0005](adr/0005-automated-tag-release-stable-asset.md)).

## Testability

The architecture is testable *because* of its seams. Each module is exercised
through its narrow interface with Core seams mocked; Core services are tested
with WordPress mocked via Brain Monkey; the serve router gets adversarial
path-traversal tests; and end-to-end behaviour (negotiation, routing, headers)
runs on WordPress Playground – never an automatic DDEV fallback
([ADR-0004](adr/0004-playground-e2e-no-auto-ddev-fallback.md)).

## Deferred to the spec (1.3)

Exact provider method signatures; the Markdown front-matter/metadata shape; cache
mechanism details (single-flight, TTL/version invalidation, file paths); settings
field definitions; negotiation edge cases (attachments, pagination); and the
llms.txt advertising/link-relation (release 2/3).

These are settled for **Release 1** (the Markdown alternate) in
[`docs/spec/markdown-alternate.md`](spec/markdown-alternate.md); the llms.txt
advertising/link-relation stays a release 2/3 concern.

## Decision record

| ADR | Decision |
|---|---|
| [0001](adr/0001-php-8-5-floor.md) | PHP 8.5 floor (owner-controllable) |
| [0002](adr/0002-vendor-converter-unscoped.md) | Bundle the converter unscoped |
| [0003](adr/0003-github-is-the-1-0-distribution-channel.md) | GitHub is the 1.0 channel |
| [0004](adr/0004-playground-e2e-no-auto-ddev-fallback.md) | Playground e2e, no auto-DDEV |
| [0005](adr/0005-automated-tag-release-stable-asset.md) | Automated tag release |
| [0006](adr/0006-deep-module-architecture.md) | Core + four deep modules |
| [0007](adr/0007-file-cached-artifacts-early-contained-router.md) | File-cached artifacts, contained router |
| [0008](adr/0008-artifact-provider-registry.md) | Artifact-provider registry |
| [0009](adr/0009-markdown-alternate-serving-strategy.md) | Markdown serving strategy |
| [0010](adr/0010-zero-config-settings-registry.md) | Zero-config settings registry |
