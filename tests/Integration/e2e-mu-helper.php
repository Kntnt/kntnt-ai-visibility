<?php
/**
 * Test-only must-use plugin for the Playground end-to-end suite.
 *
 * The e2e blueprint seeds content only once, at boot, so it cannot by itself
 * exercise cache invalidation — which needs a content change mid-run. Playground
 * runs single-worker with no authenticated curl session, so this drop-in mu-plugin
 * exposes two token-gated HTTP endpoints the e2e script drives over plain curl:
 * publish a post (to trigger the cache-version bump) and report the number of
 * cache files in an aggregate kind directory (to prove the bump pruned the
 * orphaned older version). It is copied into wp-content/mu-plugins by e2e-seed.php
 * and lives under tests/, so it is never part of a release and only ever runs
 * inside the throwaway Playground instance.
 *
 * @package Tests\Integration
 * @since   0.2.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Plugin;

// The shared secret the e2e passes so the endpoints ignore any other caller.
const KNTNT_E2E_TOKEN = 'kntnt-e2e-secret';

// Register the e2e control endpoints once WordPress (and the plugin's autoloader)
// is fully loaded; nothing fires for an ordinary request with no marker.
add_action('init', static function (): void {
    $action = isset($_GET['kntnt_e2e']) ? (string) $_GET['kntnt_e2e'] : '';
    if ($action === '' || ($_GET['token'] ?? '') !== KNTNT_E2E_TOKEN) {
        return;
    }

    // Publish a post on demand, so the e2e can drive a real save_post /
    // transition_post_status and the cache-version bump it triggers.
    if ($action === 'publish') {
        $id = wp_insert_post([
            'post_type'    => isset($_GET['type']) ? sanitize_key((string) $_GET['type']) : 'page',
            'post_title'   => isset($_GET['title']) ? sanitize_text_field(wp_unslash((string) $_GET['title'])) : 'Fresh Page',
            'post_name'    => isset($_GET['slug']) ? sanitize_title((string) $_GET['slug']) : 'fresh-page',
            'post_status'  => 'publish',
            'post_excerpt' => 'A freshly published fixture.',
            'post_content' => '<p>Fresh body.</p>',
        ], true);
        header('Content-Type: text/plain; charset=utf-8');
        echo is_wp_error($id) ? 'FAILED' : 'PUBLISHED ' . (int) $id;
        exit;
    }

    // Report how many cache files live in an aggregate kind directory, so the
    // e2e can assert a version bump left exactly one (the new version) behind.
    if ($action === 'cache_count') {
        $kind = isset($_GET['kind']) ? sanitize_key((string) $_GET['kind']) : 'llms-txt';
        $files = glob(Plugin::cache_dir() . '/' . $kind . '/*.md');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'COUNT ' . (is_array($files) ? count($files) : 0);
        exit;
    }
});
