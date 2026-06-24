<?php
/**
 * Unit tests for the Signals module's boot wiring.
 *
 * Signals\Module::boot() is pure wiring: it registers the "AI usage" settings
 * section via Core's settings registry and registers the robots_txt filter via
 * the Robots_Decorator. The test pins that contract without exercising the
 * collaborators.
 *
 * @package Tests\Unit
 * @since   0.4.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Content\Content_Types;
use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Core\Settings\Registry as Settings_Registry;
use Kntnt\Ai_Visibility\Core\Settings\Section;
use Kntnt\Ai_Visibility\Signals\Module;

beforeEach(function (): void {
    Functions\when('__')->returnArg();
    Functions\when('esc_html')->returnArg();
    Functions\when('esc_attr')->returnArg();
    Functions\when('esc_html__')->returnArg();

    // Capture which filters are registered.
    $this->filters = [];
    Functions\when('add_filter')->alias(function (string $hook) {
        $this->filters[] = $hook;
        return true;
    });

    // Capture the section registered on the settings registry mock.
    $this->sections = [];
    $registry = Mockery::mock(Settings_Registry::class);
    $registry->shouldReceive('register_section')->andReturnUsing(function (Section $section): void {
        $this->sections[] = $section;
    });

    $this->core = new Core(
        new Artifact_Registry(),
        $registry,
        Mockery::mock(Page_Markdown::class),
        Mockery::mock(Logger::class)->shouldIgnoreMissing(),
        Mockery::mock(Store::class),
        Mockery::mock(Serve_Router::class),
        Mockery::mock(Content_Types::class)->shouldIgnoreMissing(),
        Mockery::mock(Eligibility::class),
        new Markdown_Alternate(),
        Mockery::mock(Single_Flight::class),
    );
});

describe('Signals\Module::boot', function (): void {

    it('registers the AI-usage section with the settings registry', function (): void {
        (new Module())->boot($this->core);

        expect($this->sections)->toHaveCount(1);
        expect($this->sections[0])->toBeInstanceOf(Section::class);
        expect($this->sections[0]->id)->toBe('content_signals');
    });

    it('registers the robots_txt filter via the decorator', function (): void {
        (new Module())->boot($this->core);

        expect($this->filters)->toContain('robots_txt');
    });

});
