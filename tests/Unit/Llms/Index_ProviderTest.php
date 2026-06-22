<?php
/**
 * Unit tests for the llms.txt singleton provider.
 *
 * Index_Provider is a singleton provider — a rule matching exactly one path
 * (docs/spec/llms-txt.md §4.1). It matches the home-relative `/llms.txt` to a
 * version-stamped identity, delegates generation to Index_Builder as text/plain,
 * advertises nothing in Release 2, and declares its exact serve pattern so the
 * early router can serve a warm aggregate without consulting the provider.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Llms\Index_Builder;
use Kntnt\Ai_Visibility\Llms\Index_Provider;

beforeEach(function (): void {
    $this->builder = Mockery::mock(Index_Builder::class);
    $this->version = Mockery::mock(Cache_Version::class);
    $this->locator = Mockery::mock(Markdown_Alternate::class);
    $this->provider = new Index_Provider($this->builder, $this->version, $this->locator);
});

describe('Index_Provider::match', function (): void {

    it('matches /llms.txt to a version-stamped, source-less identity', function (): void {
        $this->locator->shouldReceive('home_relative')->with('/llms.txt')->andReturn('/llms.txt');
        $this->version->shouldReceive('current')->andReturn(8);

        $identity = $this->provider->match(new Request('GET', '/llms.txt'));

        expect($identity)->toBeInstanceOf(Identity::class);
        expect($identity->kind)->toBe('llms-txt');
        expect($identity->key)->toBe('llms-v8');
        expect($identity->source_id)->toBe(0);
    });

    it('returns null for any other path', function (): void {
        $this->locator->shouldReceive('home_relative')->with('/about')->andReturn('/about');

        expect($this->provider->match(new Request('GET', '/about')))->toBeNull();
    });

});

describe('Index_Provider::generate', function (): void {

    it('returns a text/plain artifact built by the index builder', function (): void {
        $this->builder->shouldReceive('build')->andReturn("# My Site\n");

        $artifact = $this->provider->generate(new Identity('llms-txt', 'llms-v8'));

        expect($artifact->bytes)->toBe("# My Site\n");
        expect($artifact->content_type)->toBe('text/plain; charset=utf-8');
        expect($artifact->last_modified)->toBeInt();
    });

});

describe('Index_Provider::advertise', function (): void {

    it('advertises nothing in Release 2', function (): void {
        expect($this->provider->advertise(new Discovery_Context(new WP_Post())))->toBe([]);
    });

});

describe('Index_Provider::serve_pattern', function (): void {

    it('declares the exact /llms.txt plain-text serve shape', function (): void {
        $pattern = $this->provider->serve_pattern();

        expect($pattern->match)->toBe('exact');
        expect($pattern->kind)->toBe('llms-txt');
        expect($pattern->path)->toBe('/llms.txt');
        expect($pattern->key)->toBe('llms');
        expect($pattern->content_type)->toBe('text/plain; charset=utf-8');
        expect($pattern->canonical)->toBeFalse();
        expect($pattern->versioned)->toBeTrue();
    });

});
