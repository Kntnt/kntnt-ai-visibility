# Markdown-alternate serving: four request forms, HTML canonical

A page's Markdown alternate is reachable four ways, carried over from the behavioural reference `Kntnt/markdown-alternate`:

1. **`.md` suffix** on a slugged URL – `/hello-world.md`, `/about/team.md`, `/category/news.md`.
2. **`?format=markdown`** on the canonical URL.
3. **`Accept: text/markdown`** on the canonical URL (the standards-correct content-negotiation form).
4. **`/index.md`** for the slug-less **root/home** (by analogy with Apache serving `/index.html` for a directory).

The `.md` URL (including `/index.md`) is the **cache-grade, advertised** path; `?format=markdown` routes to the same provider/cache; **`Accept`-on-canonical is the uncached PHP path** ([ADR-0007](0007-file-cached-artifacts-early-contained-router.md)), emitting `Vary: Accept` and a `Link: <…>.md; rel="alternate"` so well-behaved agents migrate to the cacheable URL.

**Canonical relationship:** the HTML response stays `rel="canonical"`; the `.md` is advertised from the HTML as `rel="alternate"; type="text/markdown"` and itself carries `rel="canonical"` back to the HTML, to avoid duplicate-content confusion.

## Scope and provenance

- `Kntnt/markdown-alternate` (a fork of `ProgressPlanner/markdown-alternate`) is the **behavioural** reference, not code to copy: it uses upstream's `src/` layout and couples `llms.txt` to Yoast. We reimplement its behaviour in this plugin's Core + deep-module + artifact-provider design, and our own llms.txt module replaces the Yoast integration.
- **Default scope:** every singular entry of a **front-end-viewable post type** (`is_post_type_viewable()`) gets a `.md` alternate (posts, pages and any publicly-queryable CPT – not an opt-in list). Keying on viewability rather than `publicly_queryable` is deliberate: the built-in `page` type is public and viewable but **not** `publicly_queryable`, so a `publicly_queryable`-keyed default would silently exclude pages. The **home** gets `/index.md` **if and only if the front page is a static page**. **Blog index, archives (date/taxonomy/author) and search results get no `.md` by default** – they are listings, not singular content, and widening to them is a later opt-in. So the provider's `match()`/identity covers singular front-end-viewable content plus the static-home `index.md` routing.
- Edge cases (e.g. attachments) and the Markdown **front-matter/metadata** shape (the fork emits YAML with title/date/author/featured_image/categories/tags) are deferred to the Markdown-module spec (1.3).
