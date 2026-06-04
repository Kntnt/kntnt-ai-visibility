# Architecture: a Core plus four deep feature modules, wired by Plugin

The plugin is a **Core** plus **four feature modules** (Markdown alternate, llms.txt, Link headers, Content Signals). This is the lightweight house pattern, not a formal module framework: a module is a code-organisation unit with a *thin boot contract*, instantiated and booted in dependency order by the `Plugin` singleton — not a runtime-registered, user-toggleable, third-party-extensible entity.

Every module is a **deep module** in the sense of the `coder` standard: a narrow, stable external interface hiding substantial work. The narrow interface is the seam at which the module is tested and mocked. SOLID (SRP/OCP/LSP/ISP/DIP) governs the *internals*; per the Boundary rule, ISP decomposition never surfaces on the external interface, which stays deep.

## The three seam tiers

1. **`Plugin` ↔ Module** — `Plugin` sees a module only through the thin `Module` boot contract (`boot()` + declared ordering); it knows nothing of internals.
2. **Module ↔ Core service** — Core exposes shared services behind narrow interfaces; modules depend on those abstractions and receive them injected (DIP).
3. **Module ↔ Module** — modules never reach into each other. The (c)-advertises-(a)+(b) coupling goes through a Core-owned discovery registry: (a)/(b) publish, (c) reads.

Tests target these seams: Brain Monkey mocks WordPress at the Core boundary; modules are tested through their own interfaces with Core seams mocked.

## Considered options

- **(A) Lightweight house pattern + thin contract (chosen).** Matches existing Kntnt plugins and the standard's "Plugin instantiates components in dependency order."
- **(B) Formal module system** (registry, persisted per-module enable/disable, third-party module API) — rejected as speculative: no committed requirement asks for runtime toggling or external extensibility.

## Governing rationale: YAGNI vs. the committed roadmap

YAGNI/KISS/DRY are the default and kill *speculation* (hence (B) is out). But the Charter's four modules and their documented coupling are **committed requirements with a known shape**, not guesses. So:

- We **design the Core seams for their known set of consumers now** — the discovery registry, and the content-to-Markdown service shared by (a) and (b) — even though only the Markdown module ships in release 1. The standard's "the external interface is a commitment; design it as if it cannot be changed" *requires* this: a seam built for one consumer would force a breaking change when the next module lands.
- We do **not** implement modules (b)/(c)/(d) before their releases — that would be YAGNI-wrong. The discipline holds at the level of behaviour; the foresight applies to the seams.
- DRY extracts a Core service only where modules share the *same concept*, not merely similar code.

In short: kill speculation, but let the committed roadmap earn its seams up front.
