<?php
/**
 * Unit tests for the artifact registry.
 *
 * The registry is Core's single source of truth for providers. It collects the
 * providers each module contributes and exposes them to the two Release-1
 * consumers: the serve router (which reads the serve-pattern allowlist) and the
 * request handler / discovery (which iterate the providers). The tests pin that
 * collection and exposure behaviour.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Core\Artifact\Artifact;
use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Provider;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Artifact\Serve_Pattern;

/**
 * Builds a Provider test double whose serve pattern carries the given kind.
 */
function kntnt_test_provider(string $kind, string $suffix = '.md'): Provider
{
    return new class ($kind, $suffix) implements Provider {
        public function __construct(private string $kind, private string $suffix) {}

        public function match(Request $request): ?Identity
        {
            return null;
        }

        public function generate(Identity $identity): Artifact
        {
            return new Artifact('', 'text/markdown; charset=utf-8', 0);
        }

        public function advertise(Discovery_Context $context): array
        {
            return [];
        }

        public function serve_pattern(): Serve_Pattern
        {
            return new Serve_Pattern($this->kind, $this->suffix);
        }
    };
}

describe('Artifact_Registry', function (): void {

    it('returns the providers registered with it, in order', function (): void {
        $registry = new Artifact_Registry();
        $first    = kntnt_test_provider('markdown-alternate');
        $second   = kntnt_test_provider('llms-txt', '');

        $registry->register($first);
        $registry->register($second);

        expect($registry->providers())->toBe([$first, $second]);
    });

    it('exposes one serve pattern per registered provider as the allowlist', function (): void {
        $registry = new Artifact_Registry();
        $registry->register(kntnt_test_provider('markdown-alternate', '.md'));

        $patterns = $registry->serve_patterns();

        expect($patterns)->toHaveCount(1);
        expect($patterns[0])->toBeInstanceOf(Serve_Pattern::class);
        expect($patterns[0]->kind)->toBe('markdown-alternate');
        expect($patterns[0]->suffix)->toBe('.md');
    });

    it('starts empty', function (): void {
        $registry = new Artifact_Registry();

        expect($registry->providers())->toBe([]);
        expect($registry->serve_patterns())->toBe([]);
    });

});
