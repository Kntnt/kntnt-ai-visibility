# Verify canonical-page cache protection

Accept-negotiated Markdown at a canonical page URL carries `Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate` on both 200 and 304 responses. The plugin sets `DONOTCACHEPAGE` and emits `kntnt_ai_visibility_cache_bypass` before ordinary `plugins_loaded` callbacks. A site's optional adapter owns any cache-specific API calls and readiness handling; the public plugin contains no vendor-specific cache integration. Existing `Vary` fields are combined with `Accept` on canonical HTML and negotiated Markdown. Dedicated `.md` URLs keep their cache-grade behaviour.

`Vary` governs representation selection; it does not prohibit storage. The shared-cache protection follows [RFC 9111, sections 3, 5.2.2.5 and 5.2.2.7](https://www.rfc-editor.org/rfc/rfc9111.html). The [integration actions](../EXTENSIBILITY.md#cache-integrations-and-public-content-vetoes) distinguish transport bypasses from content that must never be published as a shared artifact.

## Deployment and purge

A cache serving before WordPress cannot observe PHP constants or hooks. Confirm that every CDN, reverse proxy, server cache and WordPress page-cache layer honours `no-store` and excludes requests for negotiated Markdown from canonical HTML cache lookup and storage. If a layer ignores `Accept` in its key, configure an explicit bypass for negotiation at that layer; otherwise its pre-existing HTML entry can prevent the Markdown request from reaching WordPress. Remove any force-cache rule that overrides the response policy. Where header-based bypass is unavailable, exclude affected canonical URLs from that cache until the configuration is verified.

Deploy the fix, then purge all affected page entries through the installed cache's controls. Purge the corresponding CDN/proxy caches too. Purge after deployment even if current requests appear correct; the fix cannot alter bytes already stored outside WordPress.

## Verification on the target installation

Choose a published ordinary page whose title and public HTML content are known. Run anonymous requests through the public hostname with no cookies and no request-side `Cache-Control: no-cache` header, so the probes exercise the real cache policy. Set `page` and `md` to the site's canonical HTML and advertised Markdown URLs:

```bash
page='https://example.com/ordinary/'
md='https://example.com/ordinary.md'
curl -sS -D markdown.headers -o markdown.body -H 'Accept: text/markdown' "$page"
etag="$(awk 'tolower($1) == "etag:" { sub(/\r$/, ""); print substr($0, 7) }' markdown.headers)"
curl -sS -D conditional.headers -o conditional.body -H 'Accept: text/markdown' -H "If-None-Match: $etag" "$page"
curl -sS -D html.headers -o html.body -H 'Accept: text/html' "$page"
curl -sS -D html-repeat.headers -o html-repeat.body -H 'Accept: text/html' "$page"
curl -sS -D dedicated.headers -o dedicated.body -H 'Accept: text/html' "$md"
curl -sS -D dedicated-repeat.headers -o dedicated-repeat.body -H 'Accept: text/html' "$md"
```

1. Check that the first response is 200 Markdown with the correct public content, both `private` and `no-store`, validators and `Vary: Accept`. Any existing `Cookie` or `Accept-Encoding` variation must survive. If `Vary: *` already applies, it must remain effective.
2. Check that the conditional response is a bodyless 304 and retains the same cache protection and variation. With an unchanged document it must not be a shared-cache hit.
3. Check that both HTML requests are 200 `text/html`, display the expected page and contain no Markdown document. Their combined `Vary` fields must include `Accept` or `*`. Inspect the installed cache's debug log/cache-control header for the negotiated bypass and `Age` or other cache status headers for unexpected reuse. Record the actual headers rather than assuming every layer exposes the same diagnostic field.
4. Check that both dedicated requests are Markdown, carry validators and have no negotiated `private`/`no-store` policy or new `Accept` variation. A server-cache hit is possible only when that site's dedicated-artifact configuration supports it.
5. Purge again, repeat with HTML first and Markdown second, then repeat for each language/path policy and each external cache layer. HTML-first testing detects a cache that serves canonical HTML before the negotiation request reaches WordPress. Keep the probe bodies, headers, cache settings and installed plugin versions as deployment evidence.

## Local regression evidence and its limit

`python3 tests/Integration/playground-negotiated-cache.py` creates a disposable Playground fixture, runs the HTTP probe and stops its entire server process group. `KNTNT_CACHE_PORT` selects a free local port (default 9425). This regression is included in `bash run-tests.sh --e2e-only` and the CI e2e job. To inspect an already-running instance of `negotiated-cache-blueprint.json`, run `python3 tests/Integration/negotiated-cache-http.py http://127.0.0.1:PORT`.

The probe asserts the worker's actual PHP 8.4 runtime, real 200/304 headers, competing `Vary` fields, early bypass timing, the generic integration action and dedicated `.md` requests. A deliberately URL-only shared-cache model honours storage prohibitions and demonstrates that a preceding Markdown body cannot be replayed to an HTML visitor. The hook observer is a fixture; Playground does not provide the production server cache or CDN. These results do not validate the target installation's actual cache configuration. Access to that configuration and the target hostname is required to complete the production procedure above.
