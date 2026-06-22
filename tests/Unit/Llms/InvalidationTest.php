<?php
/**
 * Unit tests for the llms aggregate invalidation.
 *
 * The aggregates are invalidated by bumping the cache-version stamp, not by
 * delete-on-change (docs/spec/llms-txt.md §5): when a servable post is saved or
 * transitions, Llms\Invalidation bumps the version so the next request for either
 * aggregate resolves to a key with no file and rebuilds lazily. Revisions and
 * autosaves are ignored, and a non-servable post never bumps.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Llms\Invalidation;

beforeEach(function (): void {
    Functions\when('wp_is_post_revision')->justReturn(false);
    Functions\when('wp_is_post_autosave')->justReturn(false);
    $this->eligibility = Mockery::mock(Eligibility::class);
    $this->version = Mockery::mock(Cache_Version::class);
    $this->invalidation = new Invalidation($this->eligibility, $this->version);
});

describe('Invalidation::on_save', function (): void {

    it('bumps the cache version when the saved post is servable', function (): void {
        $this->eligibility->shouldReceive('is_servable')->andReturnTrue();
        $this->version->shouldReceive('bump')->once();

        $this->invalidation->on_save(7, new WP_Post());
    });

    it('does not bump for a non-servable post', function (): void {
        $this->eligibility->shouldReceive('is_servable')->andReturnFalse();
        $this->version->shouldReceive('bump')->never();

        $this->invalidation->on_save(7, new WP_Post());
    });

    it('does not bump for a revision or autosave', function (): void {
        Functions\when('wp_is_post_revision')->justReturn(true);
        $this->version->shouldReceive('bump')->never();

        $this->invalidation->on_save(7, new WP_Post());
    });

});

describe('Invalidation::on_transition', function (): void {

    it('bumps the cache version when the transitioned post is servable', function (): void {
        $this->eligibility->shouldReceive('is_servable')->andReturnTrue();
        $this->version->shouldReceive('bump')->once();

        $this->invalidation->on_transition('publish', 'draft', new WP_Post());
    });

});

describe('Invalidation::register', function (): void {

    it('hooks save_post and transition_post_status', function (): void {
        $actions = [];
        Functions\when('add_action')->alias(function (string $hook) use (&$actions): void {
            $actions[] = $hook;
        });

        $this->invalidation->register();

        expect($actions)->toContain('save_post');
        expect($actions)->toContain('transition_post_status');
    });

});
