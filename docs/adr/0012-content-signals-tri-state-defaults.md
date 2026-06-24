# Content signals are a site-wide, tri-state declaration with mission-coherent defaults

Release 4's Content Signals module declares the site's AI-usage preferences as a `Content-Signal` directive in `robots.txt` (the Content Signals Policy vocabulary: `search`, `ai-input`, `ai-train`), under a single `User-agent: *` group. It is a **declaration of usage preference, not a crawler blocklist**, and not a named-AI-bot deny list. The hard constraint that shapes everything: a `Content-Signal` is a group-level directive with no per-path or per-type axis, so the module **cannot** mirror the content-type matrix — it is one site-wide policy. It therefore registers no capability column, no artifact provider, no serve pattern and no cache; it hooks `robots_txt`, reads three settings and writes one block. This is the plugin's simplest module.

## Three states, not two

Each signal is **tri-state** — *grant* (`yes`), *reserve* (`no`) and *defer* (omitted) — faithful to the policy's own semantics, in which an omitted signal "neither grants nor restricts" the use. *Defer* is not a synonym for *reserve*: `no` is an active, machine-readable reservation of rights (in the EU, the kind the DSM Directive's text-and-data-mining opt-out contemplates), whereas omitting the signal makes no claim and leaves the use to whatever else governs it — the site's other directives, applicable law and crawler practice. In many jurisdictions an omitted signal is, in effect, tacit permission; the UI says so plainly without offering legal advice.

## Defaults: assert only what the plugin is for

The zero-config default emits exactly one line — `Content-Signal: ai-input=yes` — and defers the rest:

- **`ai-input` = grant.** Using the content as input to generate AI answers *is the plugin's purpose* (the [Charter](../Charter.md)'s "found and accurately represented by AI agents"). Modules a/b/c actively feed AI; d affirming it is the only coherent default. The zero-click trade-off (a generated answer may stand in for a visit) is real, but it is the bet the whole product makes.
- **`search` = defer.** Search indexing is already owned by WordPress core (`blog_public`) and by the SEO plugin a typical content site runs (`noindex`), and the `search` content-signal is the weakest, least-honoured of the three — it cannot override a real `noindex`. Asserting it here would add a third, conflicting place to control indexing. The module stays out of it; the deferral is passive (it says nothing and reads no SEO-plugin state, so dependency-free holds).
- **`ai-train` = defer.** Whether to be training fodder is a genuinely contested, owner-specific rights question with no return to the publisher and an irreversible downside (a trained model cannot be un-trained). The plugin must not pick a side by default; *defer* leaves it to law and practice, and the owner sets `yes`/`no` consciously.

Rejected defaults: Cloudflare's protective `search=yes, ai-train=no` (it contradicts an AI-*visibility* plugin that serves `llms-full.txt`); all-`yes` (it silently grants training the owner never chose); `search=grant` (it steps on the SEO plugin and the WP setting).

## No feature toggle

There is **no on/off switch for the module**, and none for the other three — a per-feature runtime toggle is the module-toggle framework [ADR-0006](0006-deep-module-architecture.md) ruled out, and it is redundant: modules a/b already have per-type on/off through the content-type matrix, and c only advertises what they produce. The content-signals "off" state is expressed *within* the feature, as the *defer* default — the module is always booted; only its output is conditioned.

## Consequences

- A warning surfaces (server-side, no JS — help text plus a post-save notice) when an owner makes a visibility-reducing choice: strongly on `search=no` (it may de-index you, it may be ignored, use `noindex` instead), mildly on `search=yes` (it may conflict with your other directives, which win) and on `ai-input=no` (it contradicts why the plugin was installed). `ai-train` carries explanation, not a warning.
- The module is silent in two cases, both intended: a **physical `/robots.txt`** bypasses the `robots_txt` filter (a documented limitation, not worked around — the plugin will not write to the web root), and `blog_public = 0` (the owner has globally discouraged crawlers) suppresses the block entirely.
- No `robots.txt` reference to `llms.txt` is emitted (the deferred [`llms-txt.md`](../spec/llms-txt.md) §10 item, now declined): there is no standard directive for it, `/llms.txt` is already discovered by the root convention and advertised by Release 3's `Link` headers, and adding it would couple the simplest module to the registry and conflate usage-preference with discovery.
