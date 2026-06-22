<?php
/**
 * Unit tests for the llms module's boot wiring.
 *
 * Llms\Module::boot is pure wiring (docs/spec/llms-txt.md §4): it contributes the
 * `llms` and `llms_full` capability columns to the Core matrix, registers the two
 * singleton providers with the artifact registry, and registers the request
 * handler and the invalidation hooks. The test pins that contract — the columns,
 * the providers and the hooks — without exercising the collaborators.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
use Kntnt\Ai_Visibility\Core\Content\Content_Types;
use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Core\Settings\Registry as Settings_Registry;
use Kntnt\Ai_Visibility\Llms\Full_Provider;
use Kntnt\Ai_Visibility\Llms\Index_Provider;
use Kntnt\Ai_Visibility\Llms\Module;

beforeEach(function (): void {
    Functions\when('__')->returnArg();

    $this->actions = [];
    $this->filters = [];
    Functions\when('add_action')->alias(function (string $hook) {
        $this->actions[] = $hook;
        return true;
    });
    Functions\when('add_filter')->alias(function (string $hook) {
        $this->filters[] = $hook;
        return true;
    });

    $this->artifacts = new Artifact_Registry();
    $this->columns   = [];
    $types = Mockery::mock(Content_Types::class);
    $types->shouldReceive('register_column')->andReturnUsing(function (Capability_Column $column): void {
        $this->columns[$column->key] = $column;
    });

    $this->core = new Core(
        $this->artifacts,
        Mockery::mock(Settings_Registry::class),
        Mockery::mock(Page_Markdown::class),
        Mockery::mock(Logger::class)->shouldIgnoreMissing(),
        Mockery::mock(Store::class),
        Mockery::mock(Serve_Router::class),
        $types,
        Mockery::mock(Eligibility::class),
        new Markdown_Alternate(),
        Mockery::mock(Single_Flight::class),
    );
});

describe('Module::boot', function (): void {

    it('registers the index and full singleton providers', function (): void {
        (new Module())->boot($this->core);

        $providers = $this->artifacts->providers();
        expect($providers)->toHaveCount(2);
        expect($providers[0])->toBeInstanceOf(Index_Provider::class);
        expect($providers[1])->toBeInstanceOf(Full_Provider::class);
    });

    it('contributes the llms and llms_full columns, both requiring md', function (): void {
        (new Module())->boot($this->core);

        expect($this->columns)->toHaveKeys(['llms', 'llms_full']);
        expect($this->columns['llms']->requires)->toBe('md');
        expect($this->columns['llms_full']->requires)->toBe('md');
        // llms.txt defaults on for every type; llms-full.txt defaults to pages only.
        expect(($this->columns['llms']->default)('post'))->toBeTrue();
        expect(($this->columns['llms_full']->default)('page'))->toBeTrue();
        expect(($this->columns['llms_full']->default)('post'))->toBeFalse();
    });

    it('registers the serve and invalidation hooks', function (): void {
        (new Module())->boot($this->core);

        expect($this->actions)->toContain('init');
        expect($this->actions)->toContain('template_redirect');
        expect($this->actions)->toContain('save_post');
        expect($this->actions)->toContain('transition_post_status');
        expect($this->filters)->toContain('query_vars');
    });

});
