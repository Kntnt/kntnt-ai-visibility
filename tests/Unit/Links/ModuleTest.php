<?php
/**
 * Unit tests for the Links module's boot wiring.
 *
 * Links\Module::boot() is pure wiring: it builds a Header_Emitter over the Core
 * artifact registry and registers it on the send_headers hook. The test pins that
 * contract without exercising the emitter itself.
 *
 * @package Tests\Unit
 * @since   0.3.0
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
use Kntnt\Ai_Visibility\Links\Module;

beforeEach(function (): void {
    $this->actions = [];
    Functions\when('add_action')->alias(function (string $hook) {
        $this->actions[] = $hook;
        return true;
    });
    Functions\when('add_filter')->justReturn(true);

    $this->core = new Core(
        new Artifact_Registry(),
        Mockery::mock(Settings_Registry::class),
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

describe('Links\Module::boot', function (): void {

    it('registers the send_headers action', function (): void {
        (new Module())->boot($this->core);

        expect($this->actions)->toContain('send_headers');
    });

});
