<?php
/**
 * Unit tests for the shared Page-Markdown service.
 *
 * for_post() runs the pipeline: render the content (the_content), convert the
 * HTML to GFM with the full converter (absolutising URLs against the site
 * domain), prepend the front-matter and the visible H1, and assemble. The body
 * leads with the page's H1 sourced from the post title; the title element is
 * metadata only and never appears twice. materialise() is the single-flight
 * cache write: a miss renders and stores, a hit returns the cached bytes
 * without re-rendering.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Materialisation;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;

beforeEach(function (): void {
    Functions\when('add_action')->justReturn(true);
    Functions\when('remove_action')->justReturn(true);
    Functions\when('add_filter')->justReturn(true);
    Functions\when('remove_filter')->justReturn(true);
    Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 0]);
    Functions\when('wp_set_current_user')->justReturn((object) ['ID' => 0]);
    Functions\when('setup_postdata')->justReturn(true);
    Functions\when('wp_mkdir_p')->alias(static fn(string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
    $this->logger = new Plugin_Logger(static function (string $line): void {});
    $this->base   = sys_get_temp_dir() . '/kntnt-pm-' . uniqid('', true);
    $this->store  = new File_Store(fn(): string => $this->base);
    mkdir($this->base . '/locks', 0700, true);
    $this->single_flight = new Single_Flight($this->store, $this->base . '/locks');
});

afterEach(function (): void {
    if (is_dir($this->base)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        rmdir($this->base);
    }
});

describe('Page_Markdown_Service::for_post', function (): void {

    beforeEach(function (): void {
        // the_content renders to itself in isolation; the title is the H1 source.
        Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
        Functions\when('get_the_title')->justReturn('My Title');

        $front = Mockery::mock(Front_Matter::class);
        $front->shouldReceive('build')->andReturn("---\ntitle: \"My Title\"\n---\n");

        $this->service = new Page_Markdown_Service($front, $this->single_flight, $this->logger, fn(): string => 'https://example.com');
    });

    it('assembles front-matter, the visible H1, then the converted body', function (): void {
        $post = new WP_Post();
        $post->post_content = '<p>Hello <a href="/rel">link</a></p>';

        $markdown = $this->service->for_post($post);

        expect($markdown)->toStartWith("---\ntitle: \"My Title\"\n---\n\n# My Title\n\n");
        expect($markdown)->toContain('[link](https://example.com/rel)');
    });

    it('converts GFM tables and strikethrough and absolutises image URLs', function (): void {
        $post = new WP_Post();
        $post->post_content =
            '<p><del>old</del></p>'
            . '<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'
            . '<p><img src="/img.png" alt="x"></p>';

        $markdown = $this->service->for_post($post);

        expect($markdown)->toContain('~~old~~');
        expect($markdown)->toContain('| A |');
        expect($markdown)->toContain('![x](https://example.com/img.png)');
    });

    it('leads the body with exactly one H1 from the post title', function (): void {
        $post = new WP_Post();
        $post->post_content = '<p>Body.</p>';

        $markdown = $this->service->for_post($post);

        expect(substr_count($markdown, "\n# My Title\n"))->toBe(1);
    });

    it('distinguishes an invalid public renderer from a successful empty body', function (): void {
        $post = new WP_Post();
        Functions\when('apply_filters')->alias(static fn(string $hook, mixed $value): mixed =>
            $hook === 'kntnt_ai_visibility_public_content_html' ? null : $value);

        expect(fn(): string => $this->service->for_post($post))
            ->toThrow(Kntnt\Ai_Visibility\Core\Public_Content_Rendering_Failed::class);
    });

    it('reports a thrown public renderer failure without publishing bytes and retries', function (): void {
        Functions\when('wp_json_encode')->alias(static fn(mixed $value): string|false => json_encode($value));
        $post = new WP_Post();
        $identity = new Identity('markdown-alternate', 'renderer-retry', 7);
        $fails = true;
        Functions\when('apply_filters')->alias(static function (string $hook, mixed $value) use (&$fails): mixed {
            if ($hook === 'kntnt_ai_visibility_public_content_html') {
                if ($fails) {
                    throw new RuntimeException('private integration diagnostic');
                }
                return '<p>PUBLIC-RENDERER-RECOVERED</p>';
            }
            return $value;
        });

        expect(fn(): Materialisation => $this->service->materialise($identity, $post))
            ->toThrow(Kntnt\Ai_Visibility\Core\Public_Content_Rendering_Failed::class);
        expect($this->store->has($identity))->toBeFalse();
        $fails = false;
        $result = $this->service->materialise($identity, $post);
        expect($result->bytes)->toContain('PUBLIC-RENDERER-RECOVERED');
        expect($result->persisted)->toBeTrue();
    });

    it('refuses protected source bytes even when this visitor passes the password gate', function (): void {
        $post = new WP_Post();
        $post->post_password = 'fixture';
        $post->post_content = '<p>PASSWORD-CONTENT</p>';
        Functions\when('post_password_required')->justReturn(false);
        $service = new Page_Markdown_Service(new Front_Matter(), $this->single_flight, $this->logger);

        expect(fn(): string => $service->for_post($post))->toThrow(DomainException::class);
    });

    it('hides request credentials and restores caller state when content throws', function (): void {
        $names = ['current_user', '_GET', '_POST', '_REQUEST', '_SERVER', '_COOKIE'];
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = $GLOBALS[$name] ?? null;
        }
        $caller = (object) ['ID' => 71];
        $GLOBALS['current_user'] = $caller;
        $_GET = ['member_token' => 'private'];
        $_POST = ['personalised' => 'private'];
        $_REQUEST = $_GET + $_POST;
        $_COOKIE = ['fixture_member' => 'private'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer private';
        $_SERVER['HTTP_COOKIE'] = 'fixture_member=private';
        Functions\when('wp_get_current_user')->alias(static fn(): object => $GLOBALS['current_user']);
        Functions\when('wp_set_current_user')->alias(static function (int $id): object {
            return $GLOBALS['current_user'] = (object) ['ID' => $id];
        });
        Functions\when('apply_filters')->alias(static function (string $hook, mixed $value): mixed {
            if ($hook === 'the_content') {
                expect($GLOBALS['current_user']->ID)->toBe(0);
                expect($_GET)->toBe([]);
                expect($_POST)->toBe([]);
                expect($_REQUEST)->toBe([]);
                expect($_COOKIE)->toBe([]);
                expect($_SERVER)->not->toHaveKeys(['HTTP_AUTHORIZATION', 'HTTP_COOKIE']);
                throw new RuntimeException('content integration failed');
            }
            return $value;
        });
        $post = new WP_Post();
        $service = new Page_Markdown_Service(new Front_Matter(), $this->single_flight, $this->logger);

        try {
            expect(fn(): string => $service->for_post($post))->toThrow(RuntimeException::class, 'content integration failed');
            expect($GLOBALS['current_user'])->toBe($caller);
            expect($_GET)->toBe(['member_token' => 'private']);
            expect($_COOKIE)->toBe(['fixture_member' => 'private']);
            expect($_SERVER['HTTP_AUTHORIZATION'])->toBe('Bearer private');
        } finally {
            foreach ($names as $name) {
                if ($saved[$name] === null) {
                    unset($GLOBALS[$name]);
                } else {
                    $GLOBALS[$name] = $saved[$name];
                }
            }
        }
    });

});

describe('Page_Markdown_Service::materialise', function (): void {

    it('refuses preview materialisation without populating the shared cache', function (): void {
        Functions\when('apply_filters')->alias(static fn(string $hook, mixed $value): mixed => $value);
        Functions\when('get_the_title')->justReturn('Preview');
        Functions\when('get_permalink')->justReturn('https://example.com/preview/');
        Functions\when('get_the_date')->justReturn('2026-10-08');
        Functions\when('get_the_author_meta')->justReturn('Author');
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('get_the_terms')->justReturn(false);
        $query = $_GET;
        $_GET = ['preview' => 'true', 'preview_nonce' => 'private'];
        $identity = new Identity('markdown-alternate', 'preview', 7);
        $post = new WP_Post();
        $service = new Page_Markdown_Service(new Front_Matter(), $this->single_flight, $this->logger, fn(): string => 'https://example.com');

        try {
            expect(fn(): Materialisation => $service->materialise($identity, $post))->toThrow(DomainException::class);
            expect($this->store->has($identity))->toBeFalse();
            expect($_GET)->toBe(['preview' => 'true', 'preview_nonce' => 'private']);
        } finally {
            $_GET = $query;
        }
    });

    it('publishes anonymous bytes from an authenticated caller and restores its identity', function (): void {
        $previous = $GLOBALS['current_user'] ?? null;
        $caller = (object) ['ID' => 71];
        $GLOBALS['current_user'] = $caller;
        Functions\when('wp_get_current_user')->alias(static fn(): object => $GLOBALS['current_user']);
        Functions\when('wp_set_current_user')->alias(static function (int $id): object {
            return $GLOBALS['current_user'] = (object) ['ID' => $id];
        });
        Functions\when('apply_filters')->alias(static fn(string $hook, mixed $value): mixed =>
            $hook === 'the_content'
                ? '<p>' . ($GLOBALS['current_user']->ID === 0 ? 'PUBLIC-DETAIL' : 'MEMBER-PRIVATE-DETAIL') . '</p>'
                : $value);
        Functions\when('get_the_title')->justReturn('Public page');
        Functions\when('get_permalink')->justReturn('https://example.com/public/');
        Functions\when('get_the_date')->justReturn('2026-10-08');
        Functions\when('get_the_modified_date')->justReturn('2026-10-08');
        Functions\when('get_post_field')->justReturn('');
        Functions\when('get_the_author_meta')->justReturn('Author');
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_bloginfo')->justReturn('en-GB');
        $post = new WP_Post();
        $identity = new Identity('markdown-alternate', 'anonymous', 71);
        $service = new Page_Markdown_Service(new Front_Matter(), $this->single_flight, $this->logger, fn(): string => 'https://example.com');

        try {
            $result = $service->materialise($identity, $post);
            $bytes = $result->bytes;
            expect($bytes)->toContain('PUBLIC-DETAIL')->not->toContain('MEMBER-PRIVATE-DETAIL');
            expect($result->persisted)->toBeTrue();
            expect($this->store->read($identity))->toBe($bytes);
            expect($GLOBALS['current_user'])->toBe($caller);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['current_user']);
            } else {
                $GLOBALS['current_user'] = $previous;
            }
        }
    });

    it('does not publish a protected source through direct materialisation', function (): void {
        $identity = new Identity('markdown-alternate', 'protected', 42);
        $service = new Page_Markdown_Service(new Front_Matter(), $this->single_flight, $this->logger);
        $post = new WP_Post();
        $post->post_password = 'fixture';
        $post->post_content = '<p>PASSWORD-CONTENT</p>';
        Functions\when('post_password_required')->justReturn(false);

        expect(fn(): Materialisation => $service->materialise($identity, $post))->toThrow(DomainException::class);
        expect($this->store->has($identity))->toBeFalse();
    });

    it('refuses a protected source before consulting a warm public cache', function (): void {
        $identity = new Identity('markdown-alternate', 'protected', 42);
        $this->store->write($identity, 'PASSWORD-CONTENT');
        $service = new Page_Markdown_Service(new Front_Matter(), $this->single_flight, $this->logger, fn(): string => 'https://example.com');
        $post = new WP_Post();
        $post->post_password = 'fixture';
        Functions\when('post_password_required')->justReturn(false);

        expect(fn(): Materialisation => $service->materialise($identity, $post))->toThrow(DomainException::class);
    });

    it('renders, writes the cache and returns the bytes on a miss', function (): void {
        Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
        Functions\when('get_the_title')->justReturn('T');
        $front = Mockery::mock(Front_Matter::class);
        $front->shouldReceive('build')->andReturn("---\ntitle: \"T\"\n---\n");
        $service = new Page_Markdown_Service($front, $this->single_flight, $this->logger, fn(): string => 'https://example.com');

        $identity = new Identity('markdown-alternate', 'hello', 1);
        $post = new WP_Post();
        $post->post_content = '<p>Hi</p>';

        $bytes = $service->materialise($identity, $post);

        expect($this->store->has($identity))->toBeTrue();
        expect($this->store->read($identity))->toBe($bytes->bytes);
        expect($bytes->persisted)->toBeTrue();
        expect($bytes->bytes)->toContain('# T');
    });

    it('returns the cached bytes without re-rendering on a hit', function (): void {
        // No the_content / title / converter stubs: a regeneration would fail,
        // proving the hit path never re-renders. The Front_Matter mock has no
        // expectations, so calling build() would also fail.
        $identity = new Identity('markdown-alternate', 'cached', 2);
        $this->store->write($identity, 'CACHED BYTES');
        $service = new Page_Markdown_Service(Mockery::mock(Front_Matter::class), $this->single_flight, $this->logger, fn(): string => 'https://example.com');

        $post = new WP_Post();

        expect($service->materialise($identity, $post)->bytes)->toBe('CACHED BYTES');
    });

});
