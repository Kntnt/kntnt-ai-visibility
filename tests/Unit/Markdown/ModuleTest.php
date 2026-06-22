<?php
/**
 * Unit tests for the Markdown module's boot wiring.
 *
 * Module::boot is pure wiring: given Core, it contributes its `md` capability
 * column to the content-type matrix, registers the page-Markdown provider with
 * the artifact registry, and registers the module's WordPress hooks
 * (rewrite/query-var/serve, discovery and invalidation). The matrix, eligibility
 * and clear-cache action are Core concerns now, so the module no longer touches
 * the settings registry. The test pins that contract — which collaborators get
 * registered and which hooks get added — without exercising the collaborators.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
use Kntnt\Ai_Visibility\Core\Content\Content_Types;
use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Core\Settings\Registry as Settings_Registry;
use Kntnt\Ai_Visibility\Markdown\Module;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;

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

    // A real artifact registry (so the test can read back the provider), the
    // matrix that records the registered column, and mocked Core services around.
    $this->artifacts = new Artifact_Registry();
    $this->columns   = [];
    $this->types     = Mockery::mock(Content_Types::class);
    $this->types->shouldReceive('register_column')->andReturnUsing(function (Capability_Column $column): void {
        $this->columns[] = $column;
    });
    $this->core = new Core(
        $this->artifacts,
        Mockery::mock(Settings_Registry::class),
        Mockery::mock(Page_Markdown::class),
        Mockery::mock(Logger::class)->shouldIgnoreMissing(),
        Mockery::mock(Store::class),
        Mockery::mock(Serve_Router::class),
        $this->types,
        new Eligibility($this->types),
        new Markdown_Alternate(),
    );
});

describe('Module::boot', function (): void {

    it('registers the page-Markdown provider with the artifact registry', function (): void {
        (new Module())->boot($this->core);

        expect($this->artifacts->providers())->toHaveCount(1);
        expect($this->artifacts->providers()[0])->toBeInstanceOf(Page_Markdown_Provider::class);
    });

    it('contributes the md capability column to the content-type matrix', function (): void {
        (new Module())->boot($this->core);

        expect($this->columns)->toHaveCount(1);
        expect($this->columns[0]->key)->toBe('md');
        expect($this->columns[0]->requires)->toBe('');
        // The md column is on by default for every viewable type.
        expect(($this->columns[0]->default)('post'))->toBeTrue();
    });

    it('registers the serve, discovery and invalidation hooks', function (): void {
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

});
