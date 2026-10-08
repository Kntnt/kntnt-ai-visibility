<?php
/**
 * Observes URI-reference destinations through the real public page service.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;

/**
 * Keeps all plugin collaborators real and supplies the canonical WP boundary.
 */
function kntnt_reference_document(string $canonical, string $html): string
{
    Functions\when('setup_postdata')->justReturn(true);
    Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 0]);
    Functions\when('wp_set_current_user')->justReturn((object) ['ID' => 0]);
    Functions\when('add_action')->justReturn(true);
    Functions\when('remove_action')->justReturn(true);
    Functions\when('add_filter')->justReturn(true);
    Functions\when('remove_filter')->justReturn(true);
    Functions\when('apply_filters')->alias(static fn(string $hook, mixed $value): mixed => $value);
    Functions\when('home_url')->justReturn('https://example.test/');
    Functions\when('get_option')->justReturn('posts');
    Functions\when('get_the_title')->justReturn('Source');
    Functions\when('get_permalink')->justReturn($canonical);
    Functions\when('get_the_date')->justReturn('2026-10-08');
    Functions\when('get_the_author_meta')->justReturn('Author');
    Functions\when('get_the_post_thumbnail_url')->justReturn(false);
    Functions\when('get_the_terms')->justReturn(false);
    $post = new WP_Post();
    $post->ID = 18;
    $post->post_content = $html;
    $store = new File_Store(static fn(): string => throw new RuntimeException('for_post is cache-free'));
    $service = new Page_Markdown_Service(new Front_Matter(), new Single_Flight($store), new Plugin_Logger(static function (): void {}));
    return $service->for_post($post);
}

it('resolves a child link against the canonical source directory', function (): void {
    $document = kntnt_reference_document('https://example.test/parent/source/', '<p><a href="child/">child</a></p>');

    expect($document)->toContain('[child](https://example.test/parent/source/child/)');
});

it('uses URI-reference semantics for links and images from each canonical shape', function (string $canonical, string $child, string $parent, string $query, string $fragment): void {
    $html = '<p><a href="child/">child</a> <a href="../sibling/">parent</a>'
        . '<a href="?view=print&amp;next=%2Fpart#query">query</a><a href="#local">fragment</a>'
        . '<a href="/shared/">root</a><a href="https://remote.test/absolute/">absolute</a>'
        . '<a href="//cdn.test/asset">protocol</a><img src="child/" alt="image"></p>';
    $document = kntnt_reference_document($canonical, $html);

    expect($document)->toContain('[child](' . $child . ')', '![image](' . $child . ')');
    expect($document)->toContain('[parent](' . $parent . ')');
    expect($document)->toContain('[query](' . $query . ')');
    expect($document)->toContain('[fragment](' . $fragment . ')');
    expect($document)->toContain('[root](https://example.test/shared/)', '[absolute](https://remote.test/absolute/)', '[protocol](https://cdn.test/asset)');
})->with([
    'nested directory' => [
        'https://example.test/parent/source/', 'https://example.test/parent/source/child/',
        'https://example.test/parent/sibling/', 'https://example.test/parent/source/?view=print&next=%2Fpart#query',
        'https://example.test/parent/source/#local',
    ],
    'no trailing slash' => [
        'https://example.test/parent/source', 'https://example.test/parent/child/',
        'https://example.test/sibling/', 'https://example.test/parent/source?view=print&next=%2Fpart#query',
        'https://example.test/parent/source#local',
    ],
    'dated source' => [
        'https://example.test/2026/10/08/story/', 'https://example.test/2026/10/08/story/child/',
        'https://example.test/2026/10/08/sibling/', 'https://example.test/2026/10/08/story/?view=print&next=%2Fpart#query',
        'https://example.test/2026/10/08/story/#local',
    ],
    'subdirectory and language' => [
        'https://example.test/sub/sv/parent/source/', 'https://example.test/sub/sv/parent/source/child/',
        'https://example.test/sub/sv/parent/sibling/', 'https://example.test/sub/sv/parent/source/?view=print&next=%2Fpart#query',
        'https://example.test/sub/sv/parent/source/#local',
    ],
    'plain query canonical' => [
        'https://example.test/sub/?page_id=18&lang=sv', 'https://example.test/sub/child/',
        'https://example.test/sibling/', 'https://example.test/sub/?view=print&next=%2Fpart#query',
        'https://example.test/sub/?page_id=18&lang=sv#local',
    ],
    'plain file and query canonical' => [
        'https://example.test/sub/index.php?p=18&lang=sv', 'https://example.test/sub/child/',
        'https://example.test/sibling/', 'https://example.test/sub/index.php?view=print&next=%2Fpart#query',
        'https://example.test/sub/index.php?p=18&lang=sv#local',
    ],
]);
