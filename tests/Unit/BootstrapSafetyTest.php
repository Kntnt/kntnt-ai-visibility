<?php
/**
 * Unit tests for the try/catch safety wrapper in the main plugin file.
 *
 * Simulates the bootstrap pattern from kntnt-ai-visibility.php to verify that a
 * fatal error during Plugin::get_instance() is caught and handled gracefully
 * instead of taking down the whole site.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Plugin;

/**
 * Mirrors the safe-initialisation pattern from the main plugin file: wraps
 * Plugin::get_instance() in try/catch and returns the caught Throwable, or null
 * on success.
 */
function simulate_safe_init(): ?\Throwable {
    try {
        Plugin::get_instance();
    } catch (\Throwable $e) {
        return $e;
    }
    return null;
}

describe('Bootstrap safety wrapper', function (): void {

    beforeEach(function (): void {
        kntnt_test_reset_plugin();
    });

    afterEach(function (): void {
        kntnt_test_reset_plugin();
        \Patchwork\restoreAll();
    });

    it('catches a RuntimeException during initialization', function (): void {
        \Patchwork\redefine(
            'Kntnt\Ai_Visibility\Plugin::get_instance',
            function () {
                throw new \RuntimeException('GitHub API unavailable');
            },
        );

        $caught = simulate_safe_init();

        expect($caught)->toBeInstanceOf(\RuntimeException::class);
        expect($caught->getMessage())->toBe('GitHub API unavailable');
    });

    it('catches a TypeError during initialization', function (): void {
        \Patchwork\redefine(
            'Kntnt\Ai_Visibility\Plugin::get_instance',
            function () {
                throw new \TypeError('Expected string, got null');
            },
        );

        $caught = simulate_safe_init();

        expect($caught)->toBeInstanceOf(\TypeError::class);
    });

    it('catches a fatal Error during initialization', function (): void {
        \Patchwork\redefine(
            'Kntnt\Ai_Visibility\Plugin::get_instance',
            function () {
                throw new \Error('Class "Some_Dependency" not found');
            },
        );

        $caught = simulate_safe_init();

        expect($caught)->toBeInstanceOf(\Error::class);
    });

    it('returns null when initialization succeeds', function (): void {
        \Patchwork\redefine(
            'Kntnt\Ai_Visibility\Plugin::get_instance',
            function (): Plugin {
                return Mockery::mock(Plugin::class);
            },
        );

        $caught = simulate_safe_init();

        expect($caught)->toBeNull();
    });

});
