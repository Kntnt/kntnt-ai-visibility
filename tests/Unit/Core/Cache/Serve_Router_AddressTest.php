<?php
/**
 * Classifies dedicated artifact addresses independently of cache population.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Llms\Index_Builder;
use Kntnt\Ai_Visibility\Llms\Index_Provider;
use Kntnt\Ai_Visibility\Llms\Selected_Types;

it('recognises only registered installation-relative artifact addresses before any cache file exists', function (): void {
    $types = new Content_Matrix();
    $eligibility = new Eligibility($types, new Exclusions(fn(): string => '', fn(): string => 'https://example.test/sub'));
    $locator = new Markdown_Alternate();
    $registry = new Artifact_Registry();
    $registry->register(new Index_Provider(
        new Index_Builder($eligibility, new Selected_Types($types, $eligibility), $locator),
        new Cache_Version(),
        $locator,
    ));
    // Address classification cannot depend on the cache directory or a warm file.
    $store = new File_Store(static fn(): string => throw new RuntimeException('No cache lookup is allowed'));
    $router = new Serve_Router($store, $registry, base_path: fn(): string => '/sub');

    expect($router->is_artifact_path('/sub/llms.txt'))->toBeTrue();
    foreach (['/llms.txt', '/sub/other.txt', '/sub/llms.txt/', '/sub/LLMS.TXT', '/sub/../llms.txt', "/sub/llms.txt\0", 'sub/llms.txt', ''] as $path) {
        expect($router->is_artifact_path($path))->toBeFalse();
    }
});
