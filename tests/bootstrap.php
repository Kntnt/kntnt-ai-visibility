<?php
/**
 * Test bootstrap.
 *
 * Loads the Composer autoloader, initialises Patchwork early, and registers a
 * code manipulation that strips `final` from the plugin's classes. This lets
 * both final-class mocking (Mockery) and internal-function interception
 * (Brain Monkey / Patchwork) work in the same test run.
 *
 * @package Tests
 * @since   0.1.0
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// WordPress time constants the plugin uses at construction (e.g. the cache TTL
// default). WordPress defines these at runtime; the unit suite mocks WordPress
// at the Core boundary, so define the handful the production code reads.
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 7 * 24 * 60 * 60);
}

// A minimal stand-in for WordPress's WP_Post. Defined in the PHPUnit bootstrap
// (not an autoloaded file) so it is available to the unit tests but never loaded
// by PHPStan, which gets the real WP_Post from the WordPress stubs. WP_Post is a
// plain data object; this mirrors the subset of public properties the plugin
// reads. A live WordPress install replaces it with the real class.
if (!class_exists('WP_Post')) {
    #[\AllowDynamicProperties]
    class WP_Post
    {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_content = '';
        public string $post_status = 'publish';
        public string $post_type = 'post';
        public string $post_date = '1970-01-01 00:00:00';
        public string $post_modified = '1970-01-01 00:00:00';
        public string $post_password = '';
        public int $post_author = 0;
        public string $post_name = '';
    }
}

// A minimal stand-in for WordPress's WP_Term, for the same reason as WP_Post.
if (!class_exists('WP_Term')) {
    #[\AllowDynamicProperties]
    class WP_Term
    {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $taxonomy = '';
    }
}

// The unit suite treats WordPress query/Loop operations as a system boundary.
// This stand-in only lets existing service tests enter that boundary; actual
// conditionals, queried objects, Loop state and locale are verified in Playground.
if (!class_exists('WP_Query')) {
    /** System-boundary stand-in; query semantics are tested in real WordPress. */
    #[\AllowDynamicProperties]
    class WP_Query
    {
        /** Source query arguments, without request credentials. */
        public array $query = [];
        /** Parsed arguments supplied to the boundary. */
        public array $query_vars = [];
        /** The source object handed to the Loop boundary. */
        public array $posts = [];
        /** Public service tests start outside preview context. */
        public bool $is_preview = false;

        /** Accept source arguments without copying WordPress query semantics. */
        public function parse_query(array $query): void
        {
            $this->query = $query;
            $this->query_vars = $query;
        }

        /** Delegate post-data setup to the per-test WordPress function stub. */
        public function the_post(): void
        {
            $GLOBALS['post'] = $this->posts[0];
            setup_postdata($GLOBALS['post']);
        }
    }
}

// Initialise Patchwork before any plugin class is autoloaded so every class
// passes through Patchwork's source-transformation pipeline (call interception,
// internal-function redefinition, and the final-stripping registered below).
require_once dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';

// Strip `final` from the plugin's classes so Mockery can mock them. Patchwork
// applies this alongside its built-in transformations in a single pass.
$classes_dir = realpath(dirname(__DIR__) . '/classes') . DIRECTORY_SEPARATOR;
\Patchwork\CodeManipulation\register(function (\Patchwork\CodeManipulation\Source $s) use ($classes_dir): void {
    if (!isset($s->file) || !str_starts_with($s->file, $classes_dir)) {
        return;
    }
    foreach ($s->all(T_FINAL) as $offset) {
        $next = $s->skip(\Patchwork\CodeManipulation\Source::junk(), $offset);
        if ($s->is(T_CLASS, $next)) {
            $s->splice('', $offset, 1);
        }
    }
});
