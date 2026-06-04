# Markdown artifacts are file-cached in Core and served by an early, contained router

The plugin's Markdown artifacts – per-page `.md`, `llms.txt`, `llms-full.txt` – are cached as **files** under the uploads cache directory, owned by **Core**. Modules *produce* artifacts; Core *stores and serves* them. Storing the per-page Markdown as a file means one file is simultaneously the **inner** cache (a hit skips render + convert) and the **outer** cache (an early request short-circuit skips the WordPress request lifecycle). `llms.txt` / `llms-full.txt` use the same store and router.

## Serving

- The built-in default is **the earliest plugin hook we can take**: compute a safe cache path from the request, and if a valid cache file exists, serve it and `exit`; otherwise let WordPress proceed (and lazily generate + write the file). No `advanced-cache.php` drop-in (it is almost always already owned by a page-cache plugin).
- A webserver `try_files` tier (zero PHP) is left to whoever owns the server – the files are already on disk for it; it needs a `.md` → `text/markdown` mime mapping.
- On the PHP path, Core emits the response headers itself: `Content-Type: text/markdown; charset=utf-8` (essential – otherwise Markdown is mislabelled as HTML), `Content-Length` and `Last-Modified`/`ETag` for conditional requests, plus an optional `rel="canonical"` back-link. The discovery `Link` header is **not** here – it belongs on the HTML response (module (c)), which is never short-circuited.

## Generation & invalidation

- **Lazy generation** on first request; no Cron pre-render.
- **Only public, published content is cached.** The early serve runs before WP auth/caps, so caching non-public content would leak it. Invalidation therefore fires on **status transitions** (unpublish/trash/privatise), not just edits.
- **Invalidation = delete-on-change, then regenerate by scope:** a per-entity artifact (a page's own `.md`) regenerates **immediately** (after the editor's response, so the save stays fast); aggregate artifacts (`llms.txt`, `llms-full.txt`) and bulk/global invalidations (theme/plugin/settings, cache-version bump) regenerate **lazily**, because their cost is O(site) regardless of edit size.
- Indirect changes (theme/menu/translation) need a bumpable cache-version and a TTL safety net + manual flush. (Mechanism → spec 1.3.)

## Security – the router is hardened, adversarially-tested code

Page-cache plugins have shipped path-traversal / arbitrary-file-disclosure CVEs on this exact pattern. The router must: whitelist the request shape before any filesystem access; derive a safe key and fix the base dir + `.md` extension itself (never reflect the raw URL; reject `..`, encoded traversal, null bytes); `realpath`-contain the result strictly inside the cache base (defeating traversal and symlink escape); serve only from an isolated cache dir Core alone writes; and ideally serve only artifacts present in the registry allowlist. Path-traversal payloads are a mandatory test fixture.

## Consequences

- The `.md` URL is the cache-grade, agent-advertised path. Answering `Accept` on the canonical URL stays the uncached PHP path with correct `Vary: Accept` (full negotiation strategy decided separately).
- A writable, persistent filesystem is assumed (uploads dir, which WP requires writable); offloaded/ephemeral filesystems degrade gracefully to the slow path.
- Lazy regeneration has a stampede risk (single-flight is a spec-1.3 concern); `llms-full.txt` has an O(site) cold-start softened by the cached per-page Markdown.
