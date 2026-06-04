# Kntnt AI Visibility

The plugin makes a content site's pages discoverable, readable and usable by AI agents: it exposes non-HTML representations of the content and advertises them through web standards. This glossary fixes the language the design uses for those representations and the machinery that produces them. It is a glossary, not a spec – architecture and implementation decisions live in `docs/adr/`.

## Language

**Discoverable artifact**:
A non-HTML representation the plugin exposes at its own URL for AI agents – a page's Markdown alternate, `llms.txt` or `llms-full.txt`. Each one can match a request, generate its bytes and advertise itself.
_Avoid_: resource, asset, output, file

**Artifact provider**:
The unit a feature module registers with the Core registry to contribute one kind of discoverable artifact. It encapsulates three responsibilities – match a request, generate the bytes, advertise the artifact – and is the single source of truth that the serve router, the discovery mechanism and the security allowlist all read. A provider is a *rule*, not an enumeration: one Markdown-alternate provider covers every eligible page.
_Avoid_: handler, generator (each names only one of its responsibilities)

**Markdown alternate**:
The Markdown representation of a single page or post, served by content negotiation and at a `.md` URL. A kind of **discoverable artifact**, produced by the Markdown-alternate provider.
_Avoid_: Markdown mirror, Markdown version, .md copy

**llms.txt**:
A curated, machine-readable index of the site's key content for LLMs, following the llms.txt convention. A singleton **discoverable artifact**.

**llms-full.txt**:
The site's full content concatenated as Markdown – assembled from the per-page **Markdown alternates**, never re-rendered from HTML. A singleton **discoverable artifact**.

## Example dialogue

**Dev:** When an agent requests `/about.md`, who decides whether to serve it?
**Expert:** The Markdown-alternate provider. It matches the `.md` request, and if About is public it generates the alternate – or the cached file is served. The provider is the rule; the bytes it produces for About are the discoverable artifact.

**Dev:** And `llms-full.txt` – does it convert every page again?
**Expert:** No. It's assembled from the existing Markdown alternates – the same Markdown, concatenated. Never a second HTML render.

**Dev:** So is `llms.txt` also a Markdown alternate?
**Expert:** No. "Markdown alternate" means the per-page representation specifically. `llms.txt` and `llms-full.txt` are their own discoverable artifacts – singletons, one per site.

**Dev:** Who tells agents these exist?
**Expert:** Each provider advertises itself; the discovery module turns that into `Link` headers – on a page's HTML for its Markdown alternate and site-wide for `llms.txt`.
