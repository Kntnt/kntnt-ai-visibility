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
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Core\Settings\Registry as Settings_Registry_Interface;

describe('Core', function (): void {

    it('exposes each injected service through its accessor', function (): void {
        $artifacts = Mockery::mock(Artifact_Registry_Interface::class);
        $settings  = Mockery::mock(Settings_Registry_Interface::class);
        $page      = Mockery::mock(Page_Markdown::class);
        $logger    = Mockery::mock(Logger::class);
        $cache     = Mockery::mock(Store::class);

        $core = new Core($artifacts, $settings, $page, $logger, $cache);

        expect($core->artifacts())->toBe($artifacts);
        expect($core->settings())->toBe($settings);
        expect($core->page_markdown())->toBe($page);
        expect($core->logger())->toBe($logger);
        expect($core->cache())->toBe($cache);
    });

});
