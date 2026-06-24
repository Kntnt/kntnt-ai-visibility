# Extensibility

Kntnt AI Visibility works zero-config: every artifact it produces – the Markdown alternates, `/llms.txt`, `/llms-full.txt` and the `robots.txt` content signals – is generated from sensible defaults with no setup. When you want to shape that output in code, the plugin exposes a small, stable surface of WordPress filters. Every filter is optional and the plugin behaves correctly when none is attached.

All filters share the `kntnt_ai_visibility_` prefix and are attached with the standard `add_filter()`. Each one receives the value the plugin is about to use and must return a replacement of the same shape. Where a malformed return would corrupt the output the plugin revalidates it and falls back to the safe default for that value, so a filter can never produce an invalid artifact.

## Markdown alternates

| Filter | Receives | Purpose |
|---|---|---|
| `kntnt_ai_visibility_eligible_post_types` | `string[]` of post-type slugs | The post types that get a Markdown alternate. Defaults to the types enabled for the Markdown (`.md`) column on the settings page. |
| `kntnt_ai_visibility_markdown_frontmatter` | `string[]` of YAML lines, plus `WP_Post $post` | The front-matter lines (`title`, `canonical_url`, `date`, `author` and the conditional `featured_image`, `categories`, `tags`) before they are serialised. Add, remove or rewrite lines; non-scalar entries are dropped. |

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
- [`docs/adr/0010`](adr/0010-zero-config-settings-registry.md) – the zero-config settings registry and its value-resolution order.
- [`docs/spec/content-signals.md`](spec/content-signals.md) – the content-signal policy the `kntnt_ai_visibility_content_signals` filter feeds.
