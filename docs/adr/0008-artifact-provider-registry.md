# A Core registry of artifact providers unifies serving, discovery and the security allowlist

Core holds a registry of **artifact providers** (see `CONTEXT.md`). A provider is a deep object with three responsibilities: **match** a request → an artifact identity (or nothing), **generate** the identity → bytes, **advertise** itself given a discovery context → link relations. Each feature module registers its provider(s) with the registry; the registry is the single source of truth read by three consumers – the serve router (match + generate), module (c) discovery (advertise) and security (the match-pattern allowlist).

A provider is a **rule, not an enumeration**: one Markdown-alternate provider covers every eligible page; `llms.txt` and `llms-full.txt` are singleton providers.

## Why unified, not two concerns

An artifact's identity, generation, discovery and serveability are facets of **one** entity, not coincidental overlap – and the security allowlist makes the unification pay rent (only a registered provider's pattern is serveable). To keep the registry from becoming a god-object, it exposes **focused interface slices** (a serving view for the router, a discovery view for module (c)) over the one source of truth (ISP applied internally, per ADR-0006).

## Discovery follows the conventions, not invention

- **Markdown alternate** → advertised per page via `Link: <…>.md; rel="alternate"; type="text/markdown"` (RFC 8288). The HTML response is never short-circuited by the cache, so module (c) decorates it through normal hooks.
- **llms.txt** → convention-first: the file lives at the fixed root path `/llms.txt` (the llmstxt.org discovery model, like `robots.txt`), **not** advertised per page. Whether to additionally advertise it, and via which link relation, is deferred to module (b)/(c). (RFC 9727's `api-catalog` is an API-specific precedent, not an llms.txt mechanism; there is no registered llms.txt relation yet.)

## Consequences

- Module (c) is thin: walk the registry, render each provider's discovery descriptors into `Link` headers.
- The provider interface is a committed seam – designed as if it cannot be changed.
- The registry doubles as the serve router's security allowlist.
