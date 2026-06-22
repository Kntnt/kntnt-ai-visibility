<?php
/**
 * Unit tests for the llms request handler.
 *
 * Llms\Request_Handler is the PHP path the early router falls through to on a
 * cold or invalidated cache (docs/spec/llms-txt.md §4.5): it registers the
 * `/llms.txt` and `/llms-full.txt` rewrite rules and the marker query var, then
 * on template_redirect matches the request through its singleton providers and
 * serves the materialised aggregate. The pure pieces — rule/query-var/hook
 * registration and provider matching — are pinned here; the materialise-and-serve
 * shell exits and is covered end-to-end.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Provider;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Llms\Request_Handler;

/**
 * Builds a handler over the given providers and mocked serve collaborators.
 *
 * @param list<Provider> $providers
 */
function kntnt_llms_handler(array $providers): Request_Handler
{
    return new Request_Handler(
        $providers,
        Mockery::mock(Single_Flight::class),
        Mockery::mock(Store::class),
        Mockery::mock(Serve_Router::class),
        Mockery::mock(Logger::class)->shouldIgnoreMissing(),
    );
}

describe('Request_Handler::register_rewrite_rules', function (): void {

    it('maps both singleton paths to the marker query var', function (): void {
        $rules = [];
        Functions\when('add_rewrite_rule')->alias(function (string $regex, string $query) use (&$rules): void {
            $rules[$regex] = $query;
        });

        Request_Handler::register_rewrite_rules();

        expect($rules['^llms\.txt$'])->toBe('index.php?kntnt_aiv_llms=index');
        expect($rules['^llms-full\.txt$'])->toBe('index.php?kntnt_aiv_llms=full');
    });

});

describe('Request_Handler::register_query_vars', function (): void {

    it('adds the llms marker query var', function (): void {
        expect(kntnt_llms_handler([])->register_query_vars(['existing']))->toContain('kntnt_aiv_llms');
    });

});

describe('Request_Handler::register', function (): void {

    it('hooks init, query_vars and template_redirect', function (): void {
        $actions = [];
        $filters = [];
        Functions\when('add_action')->alias(function (string $hook) use (&$actions): void {
            $actions[] = $hook;
        });
        Functions\when('add_filter')->alias(function (string $hook) use (&$filters): void {
            $filters[] = $hook;
        });

        kntnt_llms_handler([])->register();

        expect($actions)->toContain('init');
        expect($actions)->toContain('template_redirect');
        expect($filters)->toContain('query_vars');
    });

});

describe('Request_Handler::match_provider', function (): void {

    it('returns the first provider that matches, with its identity', function (): void {
        $index = Mockery::mock(Provider::class);
        $index->shouldReceive('match')->andReturnUsing(
            fn(Request $r): ?Identity => $r->path === '/llms.txt' ? new Identity('llms-txt', 'llms-v8', 0) : null,
        );
        $full = Mockery::mock(Provider::class);
        $full->shouldReceive('match')->andReturn(null);

        $match = kntnt_llms_handler([$index, $full])->match_provider(new Request('GET', '/llms.txt'));

        expect($match[0])->toBe($index);
        expect($match[1]->key)->toBe('llms-v8');
    });

    it('returns null when no provider matches', function (): void {
        $index = Mockery::mock(Provider::class);
        $index->shouldReceive('match')->andReturn(null);

        expect(kntnt_llms_handler([$index])->match_provider(new Request('GET', '/about')))->toBeNull();
    });

});
