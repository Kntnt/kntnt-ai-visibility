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
