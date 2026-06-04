<?php
/**
 * Pest configuration.
 *
 * Sets up and tears down Brain Monkey around every test and stubs the common
 * WordPress i18n and escaping functions so namespaced plugin code that calls
 * them resolves cleanly in isolation.
 *
 * @package Tests
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Monkey\setUp();

    // Stub the common WordPress i18n and escaping helpers.
    Functions\stubTranslationFunctions(); // __, _e, _n, esc_html__, esc_attr__, …
    Functions\stubEscapeFunctions();      // esc_html, esc_attr, esc_url, …
});

afterEach(function (): void {
    Monkey\tearDown();
});
