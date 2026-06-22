<?php
/**
 * Unit tests for Markdown cache invalidation.
 *
 * Per-entity: a page's own `.md` is deleted on save and on every status
 * transition, so the early router — which runs before auth — never serves a
 * file that has become non-public. Indirect changes (theme, settings) flush the
 * whole cache and bump the cache-version stamp. Revisions and autosaves are
 * ignored.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Markdown\Invalidation;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;

beforeEach(function (): void {
    Functions\when('wp_is_post_revision')->justReturn(false);
    Functions\when('wp_is_post_autosave')->justReturn(false);

    $this->identity = new Identity('markdown-alternate', 'about/team', 42);
    $this->provider = Mockery::mock(Page_Markdown_Provider::class);
    $this->cache    = Mockery::mock(Store::class);
    $this->version  = Mockery::mock(Cache_Version::class);
    $this->invalidation = new Invalidation($this->provider, $this->cache, $this->version);

    $this->post = new WP_Post();
    $this->post->ID = 42;
});

describe('Invalidation', function (): void {

    it('deletes the post cache on save', function (): void {
        $this->provider->shouldReceive('identity_for_post')->with($this->post)->andReturn($this->identity);
        $this->cache->shouldReceive('delete')->once()->with($this->identity);

        $this->invalidation->on_save(42, $this->post);
    });

    it('deletes the post cache on a status transition', function (): void {
        $this->provider->shouldReceive('identity_for_post')->andReturn($this->identity);
        $this->cache->shouldReceive('delete')->once()->with($this->identity);

        $this->invalidation->on_transition('draft', 'publish', $this->post);
    });

    it('ignores revisions', function (): void {
        Functions\when('wp_is_post_revision')->justReturn(true);
        $this->cache->shouldNotReceive('delete');

        $this->invalidation->on_save(42, $this->post);
    });

    it('ignores autosaves', function (): void {
        Functions\when('wp_is_post_autosave')->justReturn(true);
        $this->cache->shouldNotReceive('delete');

        $this->invalidation->on_save(42, $this->post);
    });

    it('flushes the cache and bumps the version on an indirect change', function (): void {
        $this->version->shouldReceive('bump')->once();
        $this->cache->shouldReceive('flush_all')->once();

        $this->invalidation->flush();
    });

});
