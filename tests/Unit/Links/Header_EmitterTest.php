<?php
/**
 * Unit tests for the RFC 8288 Link-header emitter.
 *
 * Header_Emitter::emit() hooks send_headers and drives the discovery walk: for a
 * singular page it collects per-page relations (via a page-scoped context) plus
 * site-wide relations (via a null-post context) from every registered provider,
 * deduplicates by the full (href, rel, type) triple, and emits one Link: header
 * per unique relation. On non-singular pages it skips the per-page walk. On
 * admin, REST, feed, robots.txt and 404 requests it emits nothing.
 *
 * Brain Monkey stubs all WordPress functions; Patchwork (via patchwork.json's
 * redefinable-internals list) intercepts the PHP built-in header() call so the
 * tests can assert on the emitted header strings without sending real HTTP
 * headers. The REST_REQUEST constant check is verified by testing the emit()
 * code path directly via the is_admin/is_feed guards, which exercise the same
 * early-return pattern without relying on define() (which cannot be undone
 * within a process and would pollute subsequent tests).
 *
 * @package Tests\Unit
 * @since   0.3.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Link_Relation;
use Kntnt\Ai_Visibility\Core\Artifact\Provider;
use Kntnt\Ai_Visibility\Core\Artifact\Registry;
use Kntnt\Ai_Visibility\Links\Header_Emitter;

/**
 * Builds a Header_Emitter backed by the given providers.
 *
 * @param list<Provider> $providers
 * @return Header_Emitter
 */
function kntnt_links_emitter(array $providers): Header_Emitter
{
    $registry = Mockery::mock(Registry::class);
    $registry->shouldReceive('providers')->andReturn($providers);
    return new Header_Emitter($registry);
}

beforeEach(function (): void {
    Functions\when('is_admin')->justReturn(false);
    Functions\when('is_feed')->justReturn(false);
    Functions\when('is_robots')->justReturn(false);
    Functions\when('is_404')->justReturn(false);
    Functions\when('is_singular')->justReturn(false);
    Functions\when('esc_url_raw')->returnArg();

    // Capture every header() call so tests can assert on the emitted Link:
    // strings without sending real HTTP headers. Patchwork intercepts the
    // PHP built-in because 'header' is listed in patchwork.json's
    // redefinable-internals.
    $this->emitted = [];
    Functions\when('header')->alias(function (string $value, bool $replace = true): void {
        $this->emitted[] = [$value, $replace];
    });
});

describe('Header_Emitter::register', function (): void {

    it('hooks send_headers', function (): void {
        $actions = [];
        Functions\when('add_action')->alias(function (string $hook) use (&$actions): void {
            $actions[] = $hook;
        });

        kntnt_links_emitter([])->register();

        expect($actions)->toContain('send_headers');
    });

});

describe('Header_Emitter::emit — skipped contexts', function (): void {

    it('emits nothing on admin requests', function (): void {
        Functions\when('is_admin')->justReturn(true);

        kntnt_links_emitter([])->emit();

        expect($this->emitted)->toBeEmpty();
    });

    it('emits nothing on feed requests', function (): void {
        Functions\when('is_feed')->justReturn(true);

        kntnt_links_emitter([])->emit();

        expect($this->emitted)->toBeEmpty();
    });

    it('emits nothing on robots.txt requests', function (): void {
        Functions\when('is_robots')->justReturn(true);

        kntnt_links_emitter([])->emit();

        expect($this->emitted)->toBeEmpty();
    });

    it('emits nothing on 404 requests', function (): void {
        Functions\when('is_404')->justReturn(true);

        kntnt_links_emitter([])->emit();

        expect($this->emitted)->toBeEmpty();
    });

});

describe('Header_Emitter::emit — non-singular page', function (): void {

    it('emits only the site-wide relations, no per-page relation', function (): void {
        Functions\when('is_singular')->justReturn(false);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('advertise')
            ->once()
            ->with(Mockery::on(fn(Discovery_Context $ctx): bool => $ctx->post === null))
            ->andReturn([
                new Link_Relation('https://example.com/llms.txt', 'related', 'text/plain'),
                new Link_Relation('https://example.com/llms-full.txt', 'related', 'text/plain'),
            ]);

        kntnt_links_emitter([$provider])->emit();

        expect($this->emitted)->toHaveCount(2);
        expect($this->emitted[0][0])->toBe('Link: <https://example.com/llms.txt>; rel="related"; type="text/plain"');
        expect($this->emitted[0][1])->toBeFalse();
        expect($this->emitted[1][0])->toBe('Link: <https://example.com/llms-full.txt>; rel="related"; type="text/plain"');
        expect($this->emitted[1][1])->toBeFalse();
    });

});

describe('Header_Emitter::emit — singular page', function (): void {

    it('emits per-page and site-wide relations on a singular page', function (): void {
        $post     = new WP_Post();
        $post->ID = 7;
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object')->justReturn($post);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('advertise')
            ->with(Mockery::on(fn(Discovery_Context $ctx): bool => $ctx->post === $post))
            ->andReturn([new Link_Relation('https://example.com/about.md', 'alternate', 'text/markdown')]);
        $provider->shouldReceive('advertise')
            ->with(Mockery::on(fn(Discovery_Context $ctx): bool => $ctx->post === null))
            ->andReturn([
                new Link_Relation('https://example.com/llms.txt', 'related', 'text/plain'),
                new Link_Relation('https://example.com/llms-full.txt', 'related', 'text/plain'),
            ]);

        kntnt_links_emitter([$provider])->emit();

        $headers = array_column($this->emitted, 0);
        expect($headers)->toContain('Link: <https://example.com/about.md>; rel="alternate"; type="text/markdown"');
        expect($headers)->toContain('Link: <https://example.com/llms.txt>; rel="related"; type="text/plain"');
        expect($headers)->toContain('Link: <https://example.com/llms-full.txt>; rel="related"; type="text/plain"');
        expect($this->emitted)->toHaveCount(3);
    });

    it('skips the per-page walk when get_queried_object does not return a WP_Post', function (): void {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object')->justReturn(null);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('advertise')
            ->once()
            ->with(Mockery::on(fn(Discovery_Context $ctx): bool => $ctx->post === null))
            ->andReturn([new Link_Relation('https://example.com/llms.txt', 'related', 'text/plain')]);

        kntnt_links_emitter([$provider])->emit();

        expect($this->emitted)->toHaveCount(1);
    });

});

describe('Header_Emitter::emit — deduplication', function (): void {

    it('deduplicates relations with the same (href, rel, type) triple', function (): void {
        Functions\when('is_singular')->justReturn(false);

        $relation = new Link_Relation('https://example.com/llms.txt', 'related', 'text/plain');

        $p1 = Mockery::mock(Provider::class);
        $p1->shouldReceive('advertise')->andReturn([$relation]);
        $p2 = Mockery::mock(Provider::class);
        $p2->shouldReceive('advertise')->andReturn([$relation]);

        kntnt_links_emitter([$p1, $p2])->emit();

        // Two providers return the same relation; only one header should be emitted.
        expect($this->emitted)->toHaveCount(1);
        expect($this->emitted[0][0])->toBe('Link: <https://example.com/llms.txt>; rel="related"; type="text/plain"');
    });

    it('does not deduplicate relations that differ only in rel', function (): void {
        Functions\when('is_singular')->justReturn(false);

        $p = Mockery::mock(Provider::class);
        $p->shouldReceive('advertise')->andReturn([
            new Link_Relation('https://example.com/llms.txt', 'related', 'text/plain'),
            new Link_Relation('https://example.com/llms.txt', 'alternate', 'text/plain'),
        ]);

        kntnt_links_emitter([$p])->emit();

        expect($this->emitted)->toHaveCount(2);
    });

});
