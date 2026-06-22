<?php
/**
 * Playground end-to-end content seed.
 *
 * Runs inside WordPress Playground (WASM PHP 8.5) at boot, after the plugin is
 * activated, to create the fixtures the behavioural e2e (playground-e2e.sh)
 * requests over HTTP: a published page, a slug-`index` home page, a
 * password-protected page and a draft, plus a published post. Explicit excerpts
 * give the llms.txt index deterministic descriptions to assert. It switches the
 * site to pretty permalinks and flushes the rewrite cache so the Markdown `.md`
 * and the llms singleton rules resolve, and installs the test-only e2e mu-plugin
 * (e2e-mu-helper.php) so the behavioural test can mutate content mid-run. This
 * file lives under tests/ and never ships in the release zip.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare(strict_types=1);

require_once '/wordpress/wp-load.php';

// Pretty permalinks are required for the `.md` rewrite rules to match; the plain
// default keeps the rewrite engine off.
update_option('permalink_structure', '/%postname%/');

// Create the fixture pages. Each carries a known slug, title and body so the
// e2e can assert the rendered front-matter, the visible H1 and the converted
// (and absolutised) body.
$pages = [
    [
        'post_name'    => 'about',
        'post_title'   => 'About Us',
        'post_status'  => 'publish',
        'post_excerpt' => 'The team behind the site.',
        'post_content' => "<h2>Our team</h2>\n<p>Read the <a href=\"/contact/\">contact page</a>.</p>",
    ],
    [
        'post_name'    => 'index',
        'post_title'   => 'Home',
        'post_status'  => 'publish',
        'post_content' => '<p>Welcome home.</p>',
    ],
    [
        'post_name'     => 'secret',
        'post_title'    => 'Secret',
        'post_status'   => 'publish',
        'post_password' => 'hunter2',
        'post_content'  => '<p>Members only.</p>',
    ],
    [
        'post_name'    => 'draft-item',
        'post_title'   => 'Draft Item',
        'post_status'  => 'draft',
        'post_content' => '<p>Not published.</p>',
    ],
];
foreach ($pages as $page) {
    wp_insert_post($page + ['post_type' => 'page']);
}

// A published post too, to prove resolution works for the publicly_queryable
// `post` type (resolved via url_to_postid) as well as for pages.
wp_insert_post([
    'post_type'    => 'post',
    'post_name'    => 'hello-md',
    'post_title'   => 'Hello Markdown',
    'post_status'  => 'publish',
    'post_excerpt' => 'A short post.',
    'post_content' => '<p>A post body.</p>',
]);

// Flush so the page rules and the plugin's `.md` rules both persist for the
// HTTP requests the e2e makes.
flush_rewrite_rules(true);

// Install the test-only mu-plugin so its mid-run mutation and cache-inspection
// endpoints are present on every later HTTP request (mu-plugins auto-load, no
// activation needed); it drives the cache-invalidation scenario.
$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
if (!is_dir($mu_dir)) {
    wp_mkdir_p($mu_dir);
}
copy(__DIR__ . '/e2e-mu-helper.php', $mu_dir . '/e2e-mu-helper.php');

echo "KNTNT_E2E_SEED_OK\n";
