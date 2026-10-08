<?php
/**
 * Shared dependency invalidation through real storage, SQL and publication.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\Obsolete_Artifact;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Markdown\Invalidation;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;
use Tests\Helpers\Version_Database;

beforeEach(function (): void {
    $this->previous_database = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = new Version_Database();
    $GLOBALS['wpdb']->query("INSERT INTO options VALUES ('kntnt_ai_visibility_cache_version', '5', 'off')");
    Functions\when('wp_cache_delete')->justReturn(true);
    Functions\when('wp_mkdir_p')->alias(static fn(string $path): bool => is_dir($path) || mkdir($path, 0700, true));
    Functions\when('wp_is_post_revision')->justReturn(false);
    Functions\when('wp_is_post_autosave')->justReturn(false);
    Functions\when('is_post_type_hierarchical')->justReturn(false);
    $this->base = kntnt_test_publication_directory() . '/shared-block-' . uniqid();
    $this->store = kntnt_test_file_store(fn(): string => $this->base);
    $this->version = new Cache_Version($this->store);
    $this->flight = new Single_Flight($this->store, kntnt_test_publication_directory());
    $logger = new Plugin_Logger(static function (): void {});
    $service = new Page_Markdown_Service(new Front_Matter(), $this->flight, $logger);
    $provider = new Page_Markdown_Provider($service, new Eligibility(new Content_Matrix(), new Exclusions(fn(): string => '', fn(): string => 'https://example.test')), new Markdown_Alternate());
    $this->invalidation = new Invalidation($provider, $this->store, $this->version);
    $this->block = new WP_Post();
    $this->block->ID = 99;
    $this->block->post_type = 'wp_block';
    Functions\when('get_post')->justReturn($this->block);
    $this->identities = [
        new Identity('markdown-alternate', 'dependent-a', 1),
        new Identity('markdown-alternate', 'dependent-b', 2),
        new Identity('markdown-alternate', 'nested', 3),
        new Identity('markdown-alternate', 'unrelated', 4),
        new Identity('llms-txt', 'llms-v5'),
        new Identity('llms-full', 'llms-full-v5'),
    ];
    foreach ($this->identities as $identity) {
        expect($this->store->write($identity, 'SHARED-OLD'))->toBeTrue();
    }
});

afterEach(function (): void {
    $this->store->flush_all();
    $GLOBALS['wpdb'] = $this->previous_database;
});

it('revokes dependent pages and both aggregates at every shared-block lifecycle boundary', function (string $boundary): void {
    match ($boundary) {
        'save' => $this->invalidation->on_save(99, $this->block),
        'edit' => $this->invalidation->before_update(99),
        'delete' => $this->invalidation->before_update(99),
        'draft' => $this->invalidation->on_transition('draft', 'publish', $this->block),
        'publish' => $this->invalidation->on_transition('publish', 'draft', $this->block),
        'trash' => $this->invalidation->on_transition('trash', 'publish', $this->block),
    };
    expect($this->version->current())->toBe(6);
    foreach ($this->identities as $identity) {
        expect($this->store->read($identity))->toBeNull();
    }
    // Invalidating must not eagerly render or materialise replacement artifacts.
    expect($this->store->has(new Identity('llms-full', 'llms-full-v6')))->toBeFalse();
})->with(['save', 'edit', 'delete', 'draft', 'publish', 'trash']);

it('refuses an active producer that observed the previous shared-block content', function (): void {
    $identity = new Identity('markdown-alternate', 'cold-dependent', 7);
    expect(fn() => $this->flight->once($identity, function (): string {
        $this->invalidation->on_save(99, $this->block);
        return 'SHARED-OBSOLETE';
    }))->toThrow(Obsolete_Artifact::class);
    expect($this->store->read($identity))->toBeNull();
    foreach ($this->identities as $warm) {
        expect($this->store->read($warm))->toBeNull();
    }
    $retry = $this->flight->once($identity, fn(): string => 'SHARED-CURRENT');
    expect($retry->persisted)->toBeTrue();
    expect($retry->bytes)->toBe('SHARED-CURRENT');
});

it('ignores shared-block revisions and autosaves without revoking the current source', function (string $guard): void {
    Functions\when($guard)->justReturn(true);
    $this->invalidation->on_save(99, $this->block);
    expect($this->version->current())->toBe(5);
    foreach ($this->identities as $identity) {
        expect($this->store->read($identity))->toBe('SHARED-OLD');
    }
})->with(['wp_is_post_revision', 'wp_is_post_autosave']);
