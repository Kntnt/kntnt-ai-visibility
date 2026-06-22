<?php
/**
 * Unit tests for the llms-full.txt singleton provider.
 *
 * Full_Provider mirrors Index_Provider for the full-text aggregate
 * (docs/spec/llms-txt.md §4.1): it matches the home-relative `/llms-full.txt` to
 * a version-stamped identity, delegates to Full_Builder as text/plain, advertises
 * nothing, and declares its exact serve pattern.
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
use Kntnt\Ai_Visibility\Llms\Full_Builder;
use Kntnt\Ai_Visibility\Llms\Full_Provider;

beforeEach(function (): void {
    $this->builder = Mockery::mock(Full_Builder::class);
    $this->version = Mockery::mock(Cache_Version::class);
    $this->locator = Mockery::mock(Markdown_Alternate::class);
    $this->provider = new Full_Provider($this->builder, $this->version, $this->locator);
});

describe('Full_Provider', function (): void {

    it('matches /llms-full.txt to a version-stamped identity', function (): void {
        $this->locator->shouldReceive('home_relative')->with('/llms-full.txt')->andReturn('/llms-full.txt');
        $this->version->shouldReceive('current')->andReturn(8);

        $identity = $this->provider->match(new Request('GET', '/llms-full.txt'));

        expect($identity->kind)->toBe('llms-full');
        expect($identity->key)->toBe('llms-full-v8');
        expect($identity->source_id)->toBe(0);
    });

    it('returns null for the index path', function (): void {
        $this->locator->shouldReceive('home_relative')->with('/llms.txt')->andReturn('/llms.txt');

        expect($this->provider->match(new Request('GET', '/llms.txt')))->toBeNull();
    });

    it('generates a text/plain artifact built by the full builder', function (): void {
        $this->builder->shouldReceive('build')->andReturn("# My Site\n\n# About\n");

        $artifact = $this->provider->generate(new Identity('llms-full', 'llms-full-v8'));

        expect($artifact->bytes)->toBe("# My Site\n\n# About\n");
        expect($artifact->content_type)->toBe('text/plain; charset=utf-8');
    });

    it('advertises nothing', function (): void {
        expect($this->provider->advertise(new Discovery_Context(new WP_Post())))->toBe([]);
    });

    it('declares the exact /llms-full.txt serve shape', function (): void {
        $pattern = $this->provider->serve_pattern();

        expect($pattern->match)->toBe('exact');
        expect($pattern->kind)->toBe('llms-full');
        expect($pattern->path)->toBe('/llms-full.txt');
        expect($pattern->key)->toBe('llms-full');
        expect($pattern->content_type)->toBe('text/plain; charset=utf-8');
    });

});
