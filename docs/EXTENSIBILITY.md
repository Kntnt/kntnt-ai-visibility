# Extensibility

Kntnt AI Visibility works zero-config: every artifact it produces – the Markdown alternates, `/llms.txt`, `/llms-full.txt` and the `robots.txt` content signals – is generated from sensible defaults with no setup. When you want to shape that output or connect site integrations, the plugin exposes WordPress filters and actions. These hooks are optional; the plugin needs no particular cache plugin or site adapter.

All hooks share the `kntnt_ai_visibility_` prefix and use the standard `add_filter()` or `add_action()`. Filters receive the value the plugin is about to use and must return a replacement of the documented shape. Validation and failure handling are specific to each contract; in particular, a public-body rendering failure aborts publication rather than falling back to a partial body.

## Markdown alternates

| Filter | Receives | Purpose |
|---|---|---|
| `kntnt_ai_visibility_eligible_post_types` | `string[]` of post-type slugs | The post types that get a Markdown alternate. Defaults to the types enabled for the Markdown (`.md`) column on the settings page. |
| `kntnt_ai_visibility_markdown_frontmatter` | `string[]` of YAML lines, plus `WP_Post $post` | The front-matter lines (`title`, `canonical_url`, `date`, `author` and the conditional `featured_image`, `categories`, `tags`) before they are serialised. Add, remove or rewrite lines; non-scalar entries are dropped. |

## Public content and site adapters

These hooks were introduced in 0.6.0. They let a theme integration select the body a public visitor sees and keep artifacts current when that body's dependencies change.

| Hook | Signature | Contract |
|---|---|---|
| `kntnt_ai_visibility_public_content_html` | Filter: `string $html, WP_Post $source` → `string` | Replace or extend the default block and shortcode HTML with the source's actual public theme body before conversion. Runs in the anonymous source context for explicit alternates, negotiated Markdown and every page in `llms-full.txt`. |
| `kntnt_ai_visibility_public_content_meta_keys` | Filter: `array $keys, WP_Post $source` → `array` | Declare stored fields consumed by public rendering. The initial additional list is empty; return exact keys and non-empty trailing-prefix families such as `public_sections_*`, including a repeater's root/count key. The source is the metadata owner, even when another public page consumes its fields. |
| `kntnt_ai_visibility_indirect_content_changed` | Parameterless action emitted by the integration | Signal a successfully committed change to a declared indirect dependency, such as a field-backed global option, related record, menu or translation catalogue. Revokes page and aggregate caches and prevents an in-flight producer from publishing bytes selected before the change. Rebuilding remains lazy. |

Use the supplied source rather than the visitor's request. Core establishes its post, main query, Loop and locale inside an anonymous audience scope, hides visitor credentials and restores the caller's mutable state in `finally`. This is a content-rendering context; it does not run the canonical page's complete template or HTTP lifecycle. An adapter owns any extra state and output buffers it creates and must restore them on every exit.

Select only fields and sections the public template actually publishes. Exclude navigation, headers, footers, unpublished sections and private fields. Declaring a metadata dependency controls invalidation only; it neither selects body content nor exposes all ACF fields. Declare keys from the schema independently of which values currently exist, and register dependency declarations and writer callbacks on writer requests as well as front-end requests. A bare `*` is not a supported key family. Successful native metadata writes matching a declaration turn over the whole cache; unrelated undeclared fields leave warm artifacts unchanged. Emit the indirect-change action after the final successful write, never on an ordinary read.

Return a string from the body filter; `''` is a legitimate empty body. An exception or non-string result becomes a rendering failure with a fixed plain-text, no-store `500` response and no new page artifact; a full aggregate aborts rather than publishing a partial document. A callback's `DomainException` retains the public-refusal `403` policy. Propagate failures instead of catching them and returning an empty string. See the [public theme-body contract and capture example](spec/markdown-alternate.md#431-public-theme-body-integration) and [indirect-dependency contract](spec/markdown-alternate.md#53-indirect-public-rendering-dependencies) for the complete rules.

## Cache integrations and public-content vetoes

Negotiated Markdown receives private/no-store HTTP headers and the WordPress `DONOTCACHEPAGE` convention independently of any adapter. Cache-specific code belongs in the site's own plugin. Two parameterless actions, introduced in 0.7.0, connect that code to AI Visibility:

| Action | Direction | Contract |
|---|---|---|
| `kntnt_ai_visibility_cache_bypass` | Emitted by AI Visibility | Requests a cache bypass for canonical Accept-negotiated Markdown. Register the listener before `plugins_loaded`, for example from an MU plugin; the adapter handles its cache API's readiness and reapplies the bypass if necessary. Ordinary HTML and dedicated artifact requests do not emit it. |
| `kntnt_ai_visibility_public_content_nocache` | Emitted by a content or cache integration | Refuses the current public render when its content cannot be shared. Emit during content production; Core fails closed and restores the caller's mutable state. The action has no effect outside an active public render. |

The transport bypass and content veto are distinct: an anonymous Markdown response can contain public content while still needing an uncacheable canonical URL. Forward private-content signals to the veto action, never back into the transport action. These hooks do not control a cache that serves before WordPress; see the [deployment verification procedure](operations/negotiated-cache.md).

## llms.txt and llms-full.txt

| Filter | Receives | Purpose |
|---|---|---|
| `kntnt_ai_visibility_llms_post_types` | `string[]` of post-type slugs | The post types listed in `/llms.txt`. Defaults to the types enabled for the llms.txt column. |
| `kntnt_ai_visibility_llms_full_post_types` | `string[]` of post-type slugs | The post types concatenated into `/llms-full.txt`. Defaults to the types enabled for the llms-full.txt column. |
| `kntnt_ai_visibility_llms_title` | `string` | The `/llms.txt` heading. Defaults to the site name. |
| `kntnt_ai_visibility_llms_summary` | `string` | The summary blockquote under the heading. Defaults to the site tagline. |
| `kntnt_ai_visibility_llms_intro` | `string` | The introductory line beneath the summary. |
| `kntnt_ai_visibility_llms_sections` | `array` of grouped sections, one per content type | The sections after grouping and before rendering. Reorder, relabel or filter them. |
| `kntnt_ai_visibility_llms_entry` | `array{title, url, description}`, plus `WP_Post $post` | One index entry before it is rendered as a Markdown link. The natural place to substitute an SEO plugin's title and meta description. |
| `kntnt_ai_visibility_llms_txt` | `string` | The finished `/llms.txt` document – a last-chance override of the whole file. |
| `kntnt_ai_visibility_llms_full_txt` | `string` | The finished `/llms-full.txt` document – a last-chance override of the whole file. |

## Path exclusions

The **Excluded paths** settings section lists regular-expression bodies, one per line, matched against each page's home-relative path; a match curates that page out of its Markdown alternate, `/llms.txt` and `/llms-full.txt` alike. Two filters drive the same gate from code.

| Filter | Receives | Purpose |
|---|---|---|
| `kntnt_ai_visibility_exclusion_patterns` | `string[]` of pattern bodies (delimiter- and flag-less) | The parsed exclusion patterns before they are compiled. Add or remove patterns in code; each survivor is wrapped as `#…#iu` and an invalid one is silently dropped. |
| `kntnt_ai_visibility_is_excluded` | `bool`, plus `WP_Post $post` | The final per-post exclusion verdict, after the patterns have run. Force a page in or out regardless of the configured patterns. |

## Content signals

| Filter | Receives | Purpose |
|---|---|---|
| `kntnt_ai_visibility_content_signals` | `array{search, ai_input, ai_train}` of state strings | The resolved `robots.txt` content-signal policy, overriding the saved settings. Each value is `'grant'`, `'reserve'` or `'defer'`; an unrecognised value falls back to that signal's default (`search` → defer, `ai_input` → grant, `ai_train` → defer). |

## Caching and updates

| Filter | Receives | Purpose |
|---|---|---|
| `kntnt_ai_visibility_cache_ttl` | `int` seconds | The serve-router staleness safety net – the longest a cached artifact is served before it is treated as expired. Defaults to one week (`WEEK_IN_SECONDS`). |
| `kntnt_ai_visibility_update_check_ttl` | `int` seconds | How long the GitHub update check is cached before the plugin asks again. Defaults to six hours (`6 * HOUR_IN_SECONDS`). |

## Settings value resolution

Beyond the named filters above, the settings registry resolves every field-based setting in the order saved value → code default → developer filter, exposing a dynamic per-field filter `kntnt_ai_visibility_{section}_{key}` (see [`docs/adr/0010`](adr/0010-zero-config-settings-registry.md)). Artifact output is better controlled through the dedicated filters above: the content-type matrix and the content signals are custom sections that resolve their own values, and the path-exclusions section is field-based but the gate reads its patterns through `kntnt_ai_visibility_exclusion_patterns` rather than this dynamic hook — so the per-field filter applies to any field-based section a future module adds rather than to a field present today.

## Worked examples

Expose a custom post type as a Markdown alternate and list it in `/llms.txt`:

```php
add_filter( 'kntnt_ai_visibility_eligible_post_types', static function ( array $types ): array {
	$types[] = 'product_doc';
	return array_values( array_unique( $types ) );
} );

add_filter( 'kntnt_ai_visibility_llms_post_types', static function ( array $types ): array {
	$types[] = 'product_doc';
	return array_values( array_unique( $types ) );
} );
```

Use an SEO plugin's title and meta description in the `/llms.txt` index:

```php
add_filter( 'kntnt_ai_visibility_llms_entry', static function ( array $entry, WP_Post $post ): array {
	$title = get_post_meta( $post->ID, '_my_seo_title', true );
	$description = get_post_meta( $post->ID, '_my_seo_description', true );
	if ( is_string( $title ) && $title !== '' ) {
		$entry['title'] = $title;
	}
	if ( is_string( $description ) && $description !== '' ) {
		$entry['description'] = $description;
	}
	return $entry;
}, 10, 2 );
```

Add a `language` field to every Markdown alternate's front matter:

```php
add_filter( 'kntnt_ai_visibility_markdown_frontmatter', static function ( array $lines ): array {
	$lines[] = 'language: "' . get_bloginfo( 'language' ) . '"';
	return $lines;
} );
```

Reserve AI training and keep search deferred, in code, regardless of the saved settings:

```php
add_filter( 'kntnt_ai_visibility_content_signals', static function ( array $states ): array {
	$states['ai_train'] = 'reserve';
	$states['search'] = 'defer';
	return $states;
} );
```

## See also

- [`docs/architecture.md`](architecture.md) – the Core-plus-modules design the filters hook into.
- [`docs/spec/markdown-alternate.md`](spec/markdown-alternate.md) – request negotiation, anonymous source rendering, failure handling and cache invalidation contracts.
- [`docs/operations/negotiated-cache.md`](operations/negotiated-cache.md) – shared-cache exclusions, purging and deployment verification.
- [`docs/adr/0010`](adr/0010-zero-config-settings-registry.md) – the zero-config settings registry and its value-resolution order.
- [`docs/spec/content-signals.md`](spec/content-signals.md) – the content-signal policy the `kntnt_ai_visibility_content_signals` filter feeds.
