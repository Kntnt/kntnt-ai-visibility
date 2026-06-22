<?php
/**
 * Unit tests for the plugin logger.
 *
 * The logger is silent toward visitors: diagnostics go to a sink (error_log in
 * production). To keep the tests free of global side effects, the logger takes
 * an injectable sink. The tests pin the formatted output, context encoding, and
 * the verbosity gate that keeps info/debug quiet unless explicitly enabled.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;

describe('Plugin_Logger', function (): void {

    beforeEach(function (): void {
        // The logger encodes context through wp_json_encode(); off WordPress it
        // is plain json_encode (which escapes slashes, as the assertions expect).
        Functions\when('wp_json_encode')->alias('json_encode');
    });

    it('formats an error line with level, message and JSON context', function (): void {
        $lines  = [];
        $logger = new Plugin_Logger(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->error('Cache write failed', ['path' => '/tmp/x.md']);

        expect($lines)->toHaveCount(1);
        expect($lines[0])->toContain('Kntnt AI Visibility');
        expect($lines[0])->toContain('ERROR');
        expect($lines[0])->toContain('Cache write failed');
        expect($lines[0])->toContain('{"path":"\/tmp\/x.md"}');
    });

    it('omits the context fragment when no context is given', function (): void {
        $lines  = [];
        $logger = new Plugin_Logger(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->warning('Heads up');

        expect($lines[0])->toContain('WARNING');
        expect($lines[0])->not->toContain('{');
    });

    it('suppresses info and debug by default', function (): void {
        $lines  = [];
        $logger = new Plugin_Logger(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->info('fyi');
        $logger->debug('trace');

        expect($lines)->toBe([]);
    });

    it('emits info and debug when verbose', function (): void {
        $lines  = [];
        $logger = new Plugin_Logger(function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, verbose: true);

        $logger->info('fyi');
        $logger->debug('trace');

        expect($lines)->toHaveCount(2);
        expect($lines[0])->toContain('INFO');
        expect($lines[1])->toContain('DEBUG');
    });

});
