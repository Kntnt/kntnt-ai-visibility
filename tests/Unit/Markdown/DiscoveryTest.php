<?php
/**
 * Unit tests for Markdown discovery on wp_head.
 *
 * On a singular page, discovery walks the registered providers and renders each
 * advertised relation into an HTML `<link>` tag. It emits nothing on non-singular
 * views and nothing when a provider advertises nothing (an ineligible page).
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Link_Relation;
use Kntnt\Ai_Visibility\Core\Artifact\Provider;
use Kntnt\Ai_Visibility\Core\Artifact\Registry;
use Kntnt\Ai_Visibility\Markdown\Discovery;

beforeEach(function (): void {
    Functions\when('esc_attr')->returnArg();
    Functions\when('esc_url')->returnArg();

    $this->provider = Mockery::mock(Provider::class);
    $this->registry = Mockery::mock(Registry::class);
    $this->registry->shouldReceive('providers')->andReturn([$this->provider]);
    $this->discovery = new Discovery($this->registry);

    $this->post = new WP_Post();
    $this->post->ID = 3;
    Functions\when('get_queried_object')->justReturn($this->post);
});

describe('Discovery::render', function (): void {

    it('renders a link tag for each advertised relation on a singular page', function (): void {
        Functions\when('is_singular')->justReturn(true);
        $this->provider->shouldReceive('advertise')->andReturn([
            new Link_Relation('https://example.com/about.md', 'alternate', 'text/markdown'),
        ]);

        ob_start();
        $this->discovery->render();
        $html = ob_get_clean();

        expect($html)->toContain('<link rel="alternate" type="text/markdown" href="https://example.com/about.md"');
    });

    it('renders nothing on a non-singular view', function (): void {
        Functions\when('is_singular')->justReturn(false);
        $this->provider->shouldNotReceive('advertise');

        ob_start();
        $this->discovery->render();
        $html = ob_get_clean();

        expect($html)->toBe('');
    });

    it('renders nothing when the provider advertises nothing', function (): void {
        Functions\when('is_singular')->justReturn(true);
        $this->provider->shouldReceive('advertise')->andReturn([]);

        ob_start();
        $this->discovery->render();
        $html = ob_get_clean();

        expect($html)->toBe('');
    });

});
