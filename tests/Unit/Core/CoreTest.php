<?php
/**
 * Unit tests for the Core service facade.
 *
 * Core is a narrow facade: it holds the shared services and hands them to
 * modules through accessor methods, so a module depends on Core abstractions
 * rather than constructing them. The test pins that each injected service is
 * returned unchanged.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Core\Artifact\Registry as Artifact_Registry_Interface;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Content\Content_Types;
use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Core\Settings\Registry as Settings_Registry_Interface;

describe('Core', function (): void {

    it('exposes each injected service through its accessor', function (): void {
        $artifacts = Mockery::mock(Artifact_Registry_Interface::class);
        $settings  = Mockery::mock(Settings_Registry_Interface::class);
        $page      = Mockery::mock(Page_Markdown::class);
        $logger    = Mockery::mock(Logger::class);
        $cache     = Mockery::mock(Store::class);
        $router    = Mockery::mock(Serve_Router::class);
        $types     = Mockery::mock(Content_Types::class);
        $eligibility = new Eligibility($types);
        $markdown_alternate = new Markdown_Alternate();
        $single_flight = Mockery::mock(Single_Flight::class);

        $core = new Core($artifacts, $settings, $page, $logger, $cache, $router, $types, $eligibility, $markdown_alternate, $single_flight);

        expect($core->artifacts())->toBe($artifacts);
        expect($core->settings())->toBe($settings);
        expect($core->page_markdown())->toBe($page);
        expect($core->logger())->toBe($logger);
        expect($core->cache())->toBe($cache);
        expect($core->router())->toBe($router);
        expect($core->content_types())->toBe($types);
        expect($core->eligibility())->toBe($eligibility);
        expect($core->markdown_alternate())->toBe($markdown_alternate);
        expect($core->single_flight())->toBe($single_flight);
    });

});
