<?php
/**
 * Pins UTF-8 excerpt boundaries through the complete public index builder.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Llms\Index_Builder;
use Kntnt\Ai_Visibility\Llms\Selected_Types;

/**
 * Supplies WordPress boundaries while keeping all plugin collaborators real.
 */
function kntnt_unicode_index(string $excerpt): string
{
    $post = new WP_Post();
    $post->ID = 26;
    $post->post_type = 'page';
    $post->post_status = 'publish';
    $post->post_title = 'Unicode boundary';

    Functions\when('add_action')->justReturn(true);
    Functions\when('remove_action')->justReturn(true);
    Functions\when('add_filter')->justReturn(true);
    Functions\when('remove_filter')->justReturn(true);
    Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 0]);
    Functions\when('wp_set_current_user')->justReturn((object) ['ID' => 0]);
    Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
    Functions\when('get_bloginfo')->justReturn('Unicode site');
    Functions\when('get_post_types')->justReturn(['page']);
    Functions\when('is_post_type_viewable')->justReturn(true);
    Functions\when('is_post_type_hierarchical')->justReturn(true);
    Functions\when('get_posts')->justReturn([$post]);
    Functions\when('get_post_type_object')->justReturn((object) ['labels' => (object) ['name' => 'Pages']]);
    Functions\when('get_option')->alias(fn(string $key): mixed => $key === 'home' ? 'https://example.test' : false);
    Functions\when('get_permalink')->justReturn('https://example.test/unicode/');
    Functions\when('wp_parse_url')->alias(fn(string $url, int $component = -1): mixed => parse_url($url, $component));
    Functions\when('get_the_title')->justReturn($post->post_title);
    Functions\when('get_the_excerpt')->justReturn($excerpt);
    Functions\when('strip_shortcodes')->alias(fn(string $text): string => str_replace('[gallery]', '', $text));
    Functions\when('wp_strip_all_tags')->alias(fn(string $text): string => trim(strip_tags($text)));

    $matrix = new Content_Matrix();
    $matrix->register_column(new Capability_Column('md', 'Markdown', '', fn(): bool => true));
    $matrix->register_column(new Capability_Column('llms', 'llms.txt', 'md', fn(): bool => true));
    $eligibility = new Eligibility($matrix, new Exclusions(fn(): string => '', fn(): string => 'https://example.test'));
    return (new Index_Builder($eligibility, new Selected_Types($matrix, $eligibility), new Markdown_Alternate()))->build();
}

it('keeps exactly 199 ASCII characters followed by ö intact without an ellipsis', function (): void {
    $expected = str_repeat('a', 199) . 'ö';
    $document = kntnt_unicode_index($expected);

    expect(preg_match('//u', $document))->toBe(1);
    expect($document)->toContain(': ' . $expected . "\n");
    expect($document)->not->toContain('…');
});

it('caps decoded single-line descriptions at 200 code points before the ellipsis', function (string $excerpt, string $expected): void {
    $document = kntnt_unicode_index($excerpt);

    expect(preg_match('//u', $document))->toBe(1);
    expect($document)->toContain(': ' . $expected . "\n");
})->with([
    'all multibyte at the cap' => [str_repeat('界', 200), str_repeat('界', 200)],
    'all multibyte just over the cap' => [str_repeat('界', 201), str_repeat('界', 200) . '…'],
    'odd-byte boundary just over the cap' => [str_repeat('a', 199) . 'öZ', str_repeat('a', 199) . 'ö…'],
    'mixed two-byte and four-byte characters' => [str_repeat('a', 198) . 'ö🙂Z', str_repeat('a', 198) . 'ö🙂…'],
    'entity decoded at the cap' => [str_repeat('a', 199) . '&ouml;', str_repeat('a', 199) . 'ö'],
    'stripped tags shortcode entities and line breaks' => [
        '<p>[gallery] ' . str_repeat('&#246;', 199) . "</p>\r\n<span>&amp;Z</span>",
        str_repeat('ö', 199) . '…',
    ],
]);
