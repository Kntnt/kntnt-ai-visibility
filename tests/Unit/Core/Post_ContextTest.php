<?php
/**
 * Regression tests for isolated post rendering and exception-safe restoration.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Post_Context;

it('renders the source post then restores all caller globals, including on failure', function (): void {
    $source = new WP_Post();
    $source->ID = 27;
    $previous = new WP_Post();
    $previous->ID = 42;
    $saved = $GLOBALS['post'] ?? null;
    $GLOBALS['post'] = $previous;
    Functions\when('setup_postdata')->alias(function (WP_Post $post): bool {
        $GLOBALS['id'] = $post->ID;
        return true;
    });
    $had_id = array_key_exists('id', $GLOBALS);
    $old_id = $GLOBALS['id'] ?? null;
    unset($GLOBALS['id']);
    try {
        expect(Post_Context::render($source, function () use ($source): string {
            expect($GLOBALS['post'])->toBe($source);
            expect($GLOBALS['id'])->toBe(27);
            return 'RENDERED';
        }))->toBe('RENDERED');
        expect($GLOBALS['post'])->toBe($previous);
        expect(array_key_exists('id', $GLOBALS))->toBeFalse();
        expect(fn() => Post_Context::render($source, static fn(): string => throw new RuntimeException('fixture')))
            ->toThrow(RuntimeException::class, 'fixture');
        expect($GLOBALS['post'])->toBe($previous);
        expect(array_key_exists('id', $GLOBALS))->toBeFalse();
    } finally {
        if ($saved === null) { unset($GLOBALS['post']); } else { $GLOBALS['post'] = $saved; }
        if ($had_id) { $GLOBALS['id'] = $old_id; }
    }
});
