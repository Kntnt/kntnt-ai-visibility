<?php
/**
 * Exercises the build CLI with an isolated git repository and offline tools.
 *
 * Notes and runtime bytes must describe the same tagged commit, even when the
 * checkout has moved on and has additional uncommitted edits.
 *
 * @package Tests\Unit
 * @since 0.5.1
 */

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('Tagged release build', function (): void {

    beforeEach(function (): void {
        // Keep the repository, captured outputs and builder staging isolated.
        $test_root = getenv('KNTNT_RELEASE_TEST_ROOT') ?: sys_get_temp_dir();
        $this->release_root = $test_root . '/kntnt-release-' . bin2hex(random_bytes(8));
        $this->release_repo = $this->release_root . '/repo';
        $this->release_capture = $this->release_root . '/capture';
        mkdir($this->release_repo, 0700, true);
        mkdir($this->release_capture, 0700);
        mkdir($this->release_root . '/staging', 0700);
        copy(dirname(__DIR__, 2) . '/build-release-zip.sh', $this->release_repo . '/build-release-zip.sh');

        // Intercept only external dependency installation and GitHub publishing.
        $this->release_environment = [
            'PATH' => dirname(__DIR__) . '/Fixtures/Release/bin:' . getenv('PATH'),
            'KNTNT_RELEASE_CAPTURE' => $this->release_capture,
            'TMPDIR' => $this->release_root . '/staging',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'Release regression',
            'GIT_AUTHOR_EMAIL' => 'release@example.test',
            'GIT_COMMITTER_NAME' => 'Release regression',
            'GIT_COMMITTER_EMAIL' => 'release@example.test',
        ];
        $this->release_run = function (array $command): string {
            $process = new Process($command, $this->release_repo, $this->release_environment);
            $process->mustRun();
            return $process->getOutput();
        };
        ($this->release_run)(['git', 'init', '--quiet']);
    });

    afterEach(function (): void {
        // Verify the builder cleans its own staging tree before fixture cleanup.
        try {
            expect(scandir($this->release_root . '/staging'))->toBe(['.', '..']);
            if (file_exists($this->release_capture . '/notes-path')) {
                expect(file_exists(trim(file_get_contents($this->release_capture . '/notes-path'))))->toBeFalse();
            }
        } finally {

            // Remove only this test's fixture tree, including on assertion failure.
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->release_root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->release_root);
        }
    });

    it('publishes notes and runtime bytes from the tag despite a newer dirty checkout', function (): void {
        // Tag the independently expected body, including significant formatting.
        file_put_contents($this->release_repo . '/CHANGELOG.md', <<<'CHANGELOG'
# Changelog

## [1.2.3] – 2026-10-08

### Fixed

- TAGGED-NOTES
  continuation with `code` and a literal \n.

## [1.2.2] – 2026-10-07

- PREVIOUS-NOTES

[1.2.3]: https://example.test/tag
CHANGELOG
        );
        file_put_contents($this->release_repo . '/kntnt-ai-visibility.php', 'TAGGED-CONTENT');
        ($this->release_run)(['git', 'add', '.']);
        ($this->release_run)(['git', 'commit', '--quiet', '-m', 'Tagged source']);
        ($this->release_run)(['git', 'tag', 'v1.2.3']);

        // Move HEAD and leave a further dirty edit for the same release section.
        file_put_contents($this->release_repo . '/CHANGELOG.md', "## [1.2.3]\n\nCOMMITTED-WORKTREE-NOTES\n");
        file_put_contents($this->release_repo . '/kntnt-ai-visibility.php', 'WORKTREE-CONTENT');
        ($this->release_run)(['git', 'add', '.']);
        ($this->release_run)(['git', 'commit', '--quiet', '-m', 'Newer checkout']);
        file_put_contents($this->release_repo . '/CHANGELOG.md', "## [1.2.3]\n\nWORKTREE-NOTES\n");

        // Build through the CLI; the stub captures exactly what gh would receive.
        ($this->release_run)(['bash', 'build-release-zip.sh', '--tag', 'v1.2.3', '--create']);
        $expected = "\n### Fixed\n\n- TAGGED-NOTES\n  continuation with `code` and a literal \\n.\n\n"
            . "\n**Full changelog:** https://github.com/Kntnt/kntnt-ai-visibility/blob/v1.2.3/CHANGELOG.md\n";
        expect(file_get_contents($this->release_capture . '/notes.md'))->toBe($expected);
        expect(($this->release_run)([
            'unzip', '-p', $this->release_capture . '/release.zip', 'kntnt-ai-visibility/kntnt-ai-visibility.php',
        ]))->toBe('TAGGED-CONTENT');

    });

    it('stops the tagged notes at the reference-link block', function (): void {
        // Put references directly after the final version's formatted body.
        file_put_contents($this->release_repo . '/CHANGELOG.md',
            "## [1.2.3]\n\n### Fixed\n\n- TAGGED-NOTES\n\n[1.2.3]: https://example.test/tag\nREFERENCE-TAIL\n",
        );
        file_put_contents($this->release_repo . '/kntnt-ai-visibility.php', 'TAGGED-CONTENT');
        ($this->release_run)(['git', 'add', '.']);
        ($this->release_run)(['git', 'commit', '--quiet', '-m', 'Tagged source']);
        ($this->release_run)(['git', 'tag', 'v1.2.3']);
        file_put_contents($this->release_repo . '/CHANGELOG.md', "## [1.2.3]\n\nWORKTREE-NOTES\n");

        // Preserve the body literally and omit all reference definitions.
        ($this->release_run)(['bash', 'build-release-zip.sh', '--tag', 'v1.2.3', '--create']);
        expect(file_get_contents($this->release_capture . '/notes.md'))->toBe(
            "\n### Fixed\n\n- TAGGED-NOTES\n\n"
            . "\n**Full changelog:** https://github.com/Kntnt/kntnt-ai-visibility/blob/v1.2.3/CHANGELOG.md\n",
        );
    });

    it('uses generated notes when the tagged section has no content', function (?string $changelog): void {
        // A populated working-copy section must not override a missing/empty tag.
        if ($changelog !== null) {
            file_put_contents($this->release_repo . '/CHANGELOG.md', $changelog);
        }
        file_put_contents($this->release_repo . '/kntnt-ai-visibility.php', 'TAGGED-CONTENT');
        ($this->release_run)(['git', 'add', '.']);
        ($this->release_run)(['git', 'commit', '--quiet', '-m', 'Tagged source']);
        ($this->release_run)(['git', 'tag', 'v1.2.3']);
        file_put_contents($this->release_repo . '/CHANGELOG.md', "## [1.2.3]\n\nWORKTREE-NOTES\n");

        // The documented fallback is gh's generated release body.
        ($this->release_run)(['bash', 'build-release-zip.sh', '--tag', 'v1.2.3', '--create']);
        $arguments = explode("\n", file_get_contents($this->release_capture . '/arguments'));
        expect($arguments)->toContain('--generate-notes')->not->toContain('--notes-file');
        expect(file_exists($this->release_capture . '/notes.md'))->toBeFalse();
    })->with([
        'missing changelog' => [null],
        'missing version section' => ["## [1.2.2]\n\nPREVIOUS-NOTES\n"],
        'whitespace-only version section' => ["## [1.2.3]\n\n \t\n## [1.2.2]\n\nPREVIOUS-NOTES\n"],
    ]);

    it('builds local runtime bytes from the working copy without publishing', function (): void {
        // Local builds intentionally use current files without a tag or changelog.
        file_put_contents($this->release_repo . '/kntnt-ai-visibility.php', 'WORKTREE-CONTENT');
        ($this->release_run)(['bash', 'build-release-zip.sh', '--output', $this->release_capture]);

        // Check the delivered ZIP and absence of any GitHub publishing request.
        expect(($this->release_run)([
            'unzip', '-p', $this->release_capture . '/kntnt-ai-visibility.zip', 'kntnt-ai-visibility/kntnt-ai-visibility.php',
        ]))->toBe('WORKTREE-CONTENT');
        expect(file_exists($this->release_capture . '/created'))->toBeFalse();
    });

});
