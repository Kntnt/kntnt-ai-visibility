<?php
/**
 * Tests the fixed refusal message at the early WordPress translation boundary.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Http\Failure_Response;

it('uses an already loaded catalogue for an early refusal', function (): void {
    Functions\expect('is_textdomain_loaded')->once()->with('kntnt-ai-visibility')->andReturn(true);
    Functions\when('__')->alias(function (string $message, string $domain): string {
        expect($message)->toBe('This content cannot produce a public artifact.');
        expect($domain)->toBe('kntnt-ai-visibility');
        return 'Loaded translation';
    });

    expect(Failure_Response::refusal_message(early: true))->toBe('Loaded translation');
});

it('keeps gettext filters available without loading an early catalogue', function (): void {
    Functions\expect('is_textdomain_loaded')->once()->with('kntnt-ai-visibility')->andReturn(false);
    Functions\when('__')->alias(static function (): never {
        throw new LogicException('Early translation must not attempt just-in-time loading.');
    });
    $calls = [];
    Functions\when('apply_filters')->alias(function (string $hook, string $translation, string $source, string $domain) use (&$calls): string {
        $calls[] = [$hook, $translation, $source, $domain];
        return $hook === 'gettext' ? 'Global translation' : 'Domain translation';
    });

    expect(Failure_Response::refusal_message(early: true))->toBe('Domain translation');
    expect($calls)->toBe([
        ['gettext', 'This content cannot produce a public artifact.', 'This content cannot produce a public artifact.', 'kntnt-ai-visibility'],
        ['gettext_kntnt-ai-visibility', 'Global translation', 'This content cannot produce a public artifact.', 'kntnt-ai-visibility'],
    ]);
});

it('uses normal WordPress translation during the late lifecycle', function (): void {
    Functions\when('__')->justReturn('Late translation');
    expect(Failure_Response::refusal_message())->toBe('Late translation');
});

it('keeps an invalid early gettext result out of the plain-text refusal', function (): void {
    Functions\expect('is_textdomain_loaded')->once()->with('kntnt-ai-visibility')->andReturn(false);
    Functions\when('apply_filters')->justReturn(['invalid translation']);
    expect(Failure_Response::refusal_message(early: true))->toBe('This content cannot produce a public artifact.');
});
