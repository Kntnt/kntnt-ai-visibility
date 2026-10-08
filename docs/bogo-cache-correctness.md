# Bogo and public-cache correctness fixes

Local investigation, 8 September 2026. Source baseline: `def159bc8ddcbfeb2ff51e9315914eef8c130db1` (0.5.1). Changes are unreleased and uncommitted; no repository push or deployment to a public website was performed.

## Findings and corrections

| Reproduced problem | Correction |
| --- | --- |
| Bogo's request-language `home_url()` filter removed `/sv/` from the cache identity, colliding with the English post. | `Core/Site_Url` supplies the configured installation URL for cache paths, exclusions, routing and singleton discovery. Language prefixes remain part of each page key. |
| Slug fallbacks accepted the wrong language or an unrelated address with the same final slug. | Every candidate must match the complete permalink path. Fallback queries respect language filters and inspect all same-slug candidates. |
| Translated static home alternates and warm-cache canonical headers were wrong. | Translate the home identity to `/sv/index.md`; read the exact canonical from cached front matter before using path reconstruction. |
| Shortcodes and dynamic blocks rendered without the source post, and aggregates rendered translations in the request language. | `Core/Post_Context` supplies the post globals and its Bogo locale, with exception-safe restoration. |
| Unpublishing, renaming or permanently deleting a post left its former public cache file accessible. | Invalidate the old identity before mutation. Hierarchical changes flush descendants; front-page, permalink, term and Bogo metadata changes also invalidate related cache state. Aggregate deletion hooks invalidate the index. |
| An authorised password cookie/filter allowed protected content to populate the anonymous cache. | Refuse every password-bearing post, even when the current visitor has unlocked it. |
| The outer router rejected expired files, but the inner materialiser returned the same expired bytes. | Both paths now use the same configured TTL. The locked recheck also rejects expired files. |
| Accept negotiation ignored `q=0`, exact media types and an explicit preference for HTML. | Parse exact supported media types and their quality values. |
| Excerpt truncation split UTF-8 characters. | Count and truncate characters, with WordPress's multibyte support. |
| Category/tag metadata invented `.md` archive links despite archives having no Markdown provider. | Link to the real HTML term URLs. The relevant specification has been corrected. |

The principal architectural cause was treating a request-dependent URL helper as the stable cache namespace. Related hidden dependencies were the ambient post/locale and the assumption that a post's permalink remains available after mutation. Explicit URL and render-context seams, plus lifecycle tests, prevent those assumptions from silently returning.

## Verification

The focused unit regressions were observed failing before their corresponding fixes. The disposable Playground fixture reproduced both public-cache leaks and the translated-home canonical defect before correction.

Commands:

```sh
bash run-tests.sh
composer stan
vendor/bin/phpcs --standard=phpcs.xml.dist -n
```

- Unit suite: 362 passes, no failures; one existing warning in the intentional lock-failure test.
- Playground PHP 8.4: boot smoke test and 229 passing HTTP assertions: 116 original, 19 subdirectory and 47 in each of the two Bogo prefix configurations.
- PHPStan: no analysis errors. The PHP 8.5 developer environment emits duplicate-constant warnings from the testing dependencies.
- PHPCS: the full command exits successfully with no errors; existing style/filesystem warnings remain and are configured not to affect its exit status. The warning-excluded command also passes. ShellCheck passes for the updated `run-tests.sh`.
- Coverage was not measured locally: neither PCOV nor Xdebug is installed. The CI coverage gate remains necessary before release.
- The additional harness runs from both `run-tests.sh` and the CI e2e job. Its helper endpoints exist only in disposable Playground; never deploy those test helpers to a real site's mu-plugins directory.

The local SeaTwirl stack additionally exercises the installed plugin with Bogo, Slim SEO category-base removal and `/%category%/%postname%/` post URLs. Its test lives at `/Users/thomas/Clients/SeaTwirl/www/tests/ai-visibility-integration.php` and cleans up its own content in `finally`.

## Installation and limits

### Follow-up: explicit prefixes for every language

The subsequent verification adds `KNTNT_AUDIT_EXPLICIT=1 bash tests/Integration/playground-audit.sh`. Only the disposable fixture changes Bogo's `bogo_use_implicit_lang` filter to false. The actual site's configuration and production PHP are unchanged. No unprefixed-root redirect or language-selection policy is implemented by this test.

Before correction, the expanded 47-assertion matrix passed 45 checks with `/en/` and `/sv/`, but failed both static-home HTTP `Link` assertions. The original `/` and `/sv/` configuration passed 46 and failed the Swedish static-home HTTP `Link` assertion. The explicit-prefix failure was reproduced again in the implementation session before changing runtime code: both headers advertised `/index.md`. The earlier 38-assertion matrix did not inspect these headers.

The cause was early HTTP discovery: `Links/Header_Emitter` runs on `send_headers`, before Bogo starts filtering `home_url()` on `template_redirect`. WordPress resolves the current static front page through `home_url('/')`, and Bogo's `page_link` filter returns that value unchanged for the selected front page. The resulting advertised alternate lost its language prefix.

The correction is in `Core/Markdown_Alternate`: both URL and cache-key derivation now use a shared permalink resolver. For a Bogo static front page it applies `bogo_url()` with the source post's locale explicitly. Bogo supplies the prefix policy; no language code or number of languages is hard-coded. The emitter remains on `send_headers`, and installations without Bogo retain normal permalink resolution. The optional resolver's return is checked because Bogo's filterable language regex can make its internal `preg_replace()` return null.

Both configurations now pass all 47 assertions, including the unchanged HTTP-header expectations. The complete `bash run-tests.sh` passes, including the single-language and subdirectory fixtures. Both prefix configurations are now included in that entry point and the CI e2e job. The final unit run reports 362 passes, 688 assertions and the existing lock-failure warning; PHPStan reports no errors. Three or more simultaneous languages and non-ASCII URLs have not been added to this verification.

The single changed runtime file was compared and patched into the local SeaTwirl installation. Its separate AI Visibility test passes all 26 checks. The wider Bogo test passes 26 of 27: the Swedish category-feed check fails because the site's existing PERFMATTERS `disable_rss_feeds` option is enabled. Inspection confirms the installed handler returns HTTP 410 for feeds, and `/sv/feed/` returns 410. That setting and code were left unchanged. Both scripts removed their fixtures; the original `show_on_front=posts`, `page_on_front=0` and implicit default-language prefix policy remain. Only AI Visibility's generated cache was cleared.

Only changed runtime classes are copied into the local development installation. Two unrelated pre-existing installed differences, `Core/Content/Capability_Column.php` and `Core/Settings/Section.php`, are deliberately preserved. The installed version header remains 0.5.1; these are development patches, not a published release. Clear the plugin's generated cache when applying the fixes to an installation with existing artifacts.

The plugin serves one site-wide `llms.txt` and `llms-full.txt` containing both languages, with language-specific page alternates. It does not create independent translated singleton indexes. That is deliberate and consistent with the existing singleton architecture.

This is regression-backed verification, not proof against every plugin combination or concurrent edit/render race. Member-only content implemented through third-party access-control filters, arbitrary dynamic blocks, large-site aggregate performance, upstream reverse proxies and future third-party updates require separate verification when applicable. No performance claim or search/AI ranking guarantee is made.

## Primary references

- [Bogo's language-per-post model](https://wordpress.org/plugins/bogo/), checked against installed Bogo 3.9.3 source.
- [WordPress pre_post_update](https://developer.wordpress.org/reference/hooks/pre_post_update/), used to capture old public identities.
- [WordPress setup_postdata](https://developer.wordpress.org/reference/functions/setup_postdata/), including its separate global-post requirement.
- [WordPress locale switching](https://developer.wordpress.org/reference/classes/wp_locale_switcher/switch_to_locale/), used with restoration around per-post rendering.
