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
