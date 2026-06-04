# Kntnt AI Visibility

Kntnt AI Visibility is a WordPress plugin that makes content-rich websites discoverable, visible and readable by AI agents.

## Position statement

For content-rich websites — corporate sites, online magazines and blogs — that want to be found and accurately represented by AI agents such as ChatGPT, Claude, Gemini and Perplexity, Kntnt AI Visibility is a comprehensive AI-visibility plugin for WordPress. It makes your entire site discoverable, visible and readable to AI agents — and puts you in control of how your content may be used — with no technical setup. Unlike other tools, which each solve only one piece of the puzzle and are often built for e-commerce or dependent on a separate SEO plugin, Kntnt AI Visibility does it all in a single plugin, built exclusively for content sites: simple, complete and dependency-free.

## Vision

A website where a content site is understood just as well by people as by AI agents — without the publisher losing control over how the content may be used.

## Mission

To provide content-rich websites with a simple, standards-compliant way to make their WordPress content agent-friendly: serve plain Markdown via proper content negotiation, publish a curated llms.txt file, make everything discoverable via Link headers, and declare their AI usage preferences — with a single installation and zero configuration.

## Objectives

Release a 1.0 version — distributed via GitHub — which, for a typical content site, provides four features out of the box, without relying on SEO plugins or other plugins: (a) Markdown version of each page, (b) automatically generated llms.txt + llms-full.txt files, (c) RFC 8288/9727-compliant Link headers for agent discovery, and (d) content signals in robots.txt.

## Market

The existing landscape splits into three overlapping categories, and the defining problem is that almost no plugin spans more than one of them — leaving content publishers to stitch together several tools, most of which are built for e-commerce or assume a separate SEO plugin.

**Markdown via content negotiation.** This is where our Markdown module competes most directly. ProgressPlanner/markdown-alternate (Joost de Valk), on which our Kntnt/markdown-alternate fork is based, serves posts and pages as clean Markdown via `.md` URLs, `Accept: text/markdown` negotiation and a `?format=markdown` fallback, with alternate-discovery links, transient caching and a custom-post-type filter — but it does Markdown only, leans on Yoast for llms.txt, lives on GitHub rather than wordpress.org, and renders-then-converts with a general-purpose converter. Worth copying from this category: Roots/post-content-to-markdown's correctness stance (answer the `Accept` header on the canonical URL rather than only offering a `.md` mirror); illodev/markdown-negotiation-for-agents' clean standards posture (Link header, `rel="alternate"`, no User-Agent sniffing, a token-count header); Chancery Lane's pre-generated static `.md` files for performance; and sa-ai-markdown's WP-Cron pre-generation. The weakness shared across the category is scope — each does Markdown and little else, and conversion fidelity is rarely a priority — which is exactly where bundling kntnt-html-to-markdown (the v2 JohannesKaufmann port) is our edge.

**llms.txt generators.** A crowded and fast-growing category (Magazine3, LLMs.txt Curator, LLMagnet, WPGeared, Acowebs, aiready, wp-llms-txt, plus Yoast's built-in generation). LLMs.txt Curator is the most mature and the best to study for UX: curation, `llms-full.txt`, schema-aware descriptions, scheduled regeneration, WP-CLI and REST. Worth copying: `llms-full.txt`, scheduled and automatic regeneration, schema-aware descriptions, and per-post-type selection. The weaknesses are consistent — most are WooCommerce-oriented or depend on an SEO plugin to decide what to include, and several pile on "AI visibility score" dashboards and crawler analytics we do not need for 1.0.

**Content Signals in robots.txt.** Thinly served. AI Content Signals appends `search` / `ai-input` / `ai-train` directives through the `robots_txt` filter and does that one thing cleanly, which is the approach worth copying. Nothing in this category combines it with Markdown or llms.txt.

**The gap, and our thesis.** No plugin combines high-fidelity Markdown (via proper content negotiation), llms.txt and llms-full.txt, RFC 8288/9727 Link-header discovery, and Content Signals — built for content sites, with zero configuration and no SEO-plugin or e-commerce dependency. That combination is the product. It also hedges a real risk: the "Markdown for bots" premise is contested (Google's John Mueller has publicly dismissed it), so positioning on the full discovery-and-preferences stack — not Markdown alone — is the more durable bet.

## Plan

A deliberately high-level, four-step plan. Step 1 stands up the project and ships the core plus the Markdown module. Steps 2–4 each add one module and repeat the same sub-plan as 1.3–1.5: write the specification, write unit and e2e tests for red/green TDD, then implement against them.

1. First release — core + Markdown alternate
   1.1. Create the GitHub repository and scaffold everything that needs to be in place (plugin bootstrap, autoloading, build and test tooling, CI).
   1.2. Create a design document for a modular plugin: a core plus four modules — (a) a Kntnt/markdown-alternate equivalent, (b) llms.txt, (c) Link headers, and (d) Content Signals in robots.txt.
   1.3. Write the specification for the core and the Markdown-alternate module.
   1.4. Write unit and e2e tests for red/green TDD.
   1.5. Implement the core and the Markdown-alternate module.
2. llms.txt module (b) — generate llms.txt and llms-full.txt.
3. Link-headers module (c) — RFC 8288/9727 agent-discovery headers that advertise the Markdown alternates and the llms.txt file.
4. Content Signals module (d) — declare AI usage preferences in robots.txt.

Order rationale: (c) advertises the artifacts that (a) and (b) produce, so it comes after both; (d) is independent and small — a natural capstone. Each step is independently shippable and adds user-visible value on its own.
