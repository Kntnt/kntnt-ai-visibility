<?php
/**
 * Unit tests for the Markdown module's boot wiring.
 *
 * Module::boot is pure wiring: given Core, it registers the page-Markdown
 * provider with the artifact registry, contributes the settings section, and
 * registers the module's WordPress hooks (rewrite/query-var/serve, discovery,
 * invalidation and the clear-cache admin action). The test pins that contract —
 * which collaborators get registered and which hooks get added — without
 * exercising the collaborators themselves, each of which has its own tests.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Core\Settings\Registry as Settings_Registry;
use Kntnt\Ai_Visibility\Core\Settings\Section;
use Kntnt\Ai_Visibility\Markdown\Module;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;
use Kntnt\Ai_Visibility\Markdown\Settings;

beforeEach(function (): void {
    Functions\when('__')->returnArg();

    // Record every hook the boot registers so the test can assert the wiring.
    $this->actions = [];
    $this->filters = [];
    Functions\when('add_action')->alias(function (string $hook, $cb = null) {
        $this->actions[] = $hook;
        return true;
    });
    Functions\when('add_filter')->alias(function (string $hook, $cb = null) {
        $this->filters[] = $hook;
        return true;
    });

    // A real artifact registry (so the test can read back the provider) and
    // mocked Core services around it.
    $this->artifacts = new Artifact_Registry();
    $this->settings  = Mockery::mock(Settings_Registry::class);
    $this->core = new Core(
        $this->artifacts,
        $this->settings,
        Mockery::mock(Page_Markdown::class),
        Mockery::mock(Logger::class)->shouldIgnoreMissing(),
        Mockery::mock(Store::class),
        Mockery::mock(Serve_Router::class),
    );
});

describe('Module::boot', function (): void {

    it('registers the page-Markdown provider with the artifact registry', function (): void {
        $this->settings->shouldReceive('register_section');

        (new Module())->boot($this->core);

        expect($this->artifacts->providers())->toHaveCount(1);
        expect($this->artifacts->providers()[0])->toBeInstanceOf(Page_Markdown_Provider::class);
    });

    it('contributes the markdown settings section', function (): void {
        $this->settings->shouldReceive('register_section')
            ->once()
            ->with(Mockery::on(static fn($section): bool => $section instanceof Section && $section->id === 'markdown'));

        (new Module())->boot($this->core);
    });

    it('registers the serve, discovery and invalidation hooks', function (): void {
        $this->settings->shouldReceive('register_section');

        (new Module())->boot($this->core);

        expect($this->actions)->toContain('init');
        expect($this->actions)->toContain('template_redirect');
        expect($this->actions)->toContain('wp_head');
        expect($this->actions)->toContain('save_post');
        expect($this->actions)->toContain('transition_post_status');
        expect($this->actions)->toContain('switch_theme');
        expect($this->actions)->toContain('update_option_kntnt_ai_visibility');
        expect($this->filters)->toContain('query_vars');
    });

    it('registers the clear-cache admin_post action', function (): void {
        $this->settings->shouldReceive('register_section');

        (new Module())->boot($this->core);

        expect($this->actions)->toContain('admin_post_' . Settings::CLEAR_CACHE_ACTION);
    });

});
