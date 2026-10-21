<?php
/**
 * Exercises bin/build-release-assets with an isolated git repository and
 * offline tools.
 *
 * The zip must describe the committed HEAD, hold only runtime files under one
 * top-level plugin directory and refuse a tagged HEAD whose header disagrees.
 * Publishing is the release's job, so the build never calls gh.
 *
 * @package Tests\Unit
 * @since 0.5.1
 */

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('Release asset build', function (): void {

    beforeEach(function (): void {
        // Keep the repository, captured outputs and builder staging isolated.
        $test_root = getenv('KNTNT_RELEASE_TEST_ROOT') ?: sys_get_temp_dir();
        $this->release_root = $test_root . '/kntnt-release-' . bin2hex(random_bytes(8));
        $this->release_repo = $this->release_root . '/repo';
        $this->release_capture = $this->release_root . '/capture';
        $this->release_zip = $this->release_repo . '/release-assets/kntnt-ai-visibility.zip';
        mkdir($this->release_repo . '/bin', 0700, true);
        mkdir($this->release_capture, 0700);
        mkdir($this->release_root . '/staging', 0700);
        mkdir($this->release_root . '/tripwire', 0700);
        $source = dirname(__DIR__, 2);
        copy($source . '/bin/build-release-assets', $this->release_repo . '/bin/build-release-assets');
        chmod($this->release_repo . '/bin/build-release-assets', 0755);

        // Any gh call is a failure: record it and refuse.
        file_put_contents($this->release_root . '/tripwire/gh', <<<'GH'
#!/usr/bin/env bash
printf '%s\n' "$@" > "$KNTNT_RELEASE_CAPTURE/gh"
exit 1
GH
        );
        chmod($this->release_root . '/tripwire/gh', 0755);

        // Intercept dependency installation and GitHub; keep git configuration out.
        $this->release_environment = [
            'PATH' => $this->release_root . '/tripwire:' . dirname(__DIR__) . '/Fixtures/Release/bin:' . getenv('PATH'),
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
        $this->release_build = function (array $environment = []): Process {
            $process = new Process(
                ['bin/build-release-assets'],
                $this->release_repo,
                $environment + $this->release_environment,
            );
            $process->run();
            return $process;
        };
        $this->release_header = function (string $version, string $marker = 'COMMITTED'): void {
            file_put_contents(
                $this->release_repo . '/kntnt-ai-visibility.php',
                "<?php\n/**\n * Plugin Name:       Kntnt AI Visibility\n * Version:           {$version}\n */\n// {$marker}\n",
            );
        };

        // Seed a minimal plugin: every runtime name, plus files that must not ship.
        $files = [
            'autoloader.php' => "<?php\n",
            'classes/Plugin.php' => "<?php\n",
            'composer.json' => "{}\n",
            'composer.lock' => "{}\n",
            'install.php' => "<?php\n",
            'languages/kntnt-ai-visibility.pot' => "msgid \"\"\n",
            'LICENSE' => "GPL\n",
            'README.md' => "# Kntnt AI Visibility\n",
            'uninstall.php' => "<?php\n",
            'CHANGELOG.md' => "# Changelog\n",
            'docs/adr/0001-example.md' => "# Example\n",
            'tests/Unit/ExampleTest.php' => "<?php\n",
            'phpunit.xml' => "<phpunit/>\n",
        ];
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($this->release_repo . '/' . $path))) {
                mkdir(dirname($this->release_repo . '/' . $path), 0700, true);
            }
            file_put_contents($this->release_repo . '/' . $path, $content);
        }
        copy($source . '/.gitattributes', $this->release_repo . '/.gitattributes');
        ($this->release_header)('1.2.3');

        ($this->release_run)(['git', 'init', '--quiet']);
        ($this->release_run)(['git', 'add', '.']);
        ($this->release_run)(['git', 'commit', '--quiet', '-m', 'Plugin source']);
    });

    afterEach(function (): void {
        // Verify the builder cleans its own staging tree and never publishes.
        try {
            expect(scandir($this->release_root . '/staging'))->toBe(['.', '..']);
            expect(file_exists($this->release_capture . '/gh'))->toBeFalse();
        } finally {

            // Remove only this test's fixture tree, including on assertion failure.
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->release_root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->release_root);
        }
    });

    it('ships only runtime files and a production vendor/ under the plugin directory', function (): void {
        // Build an untagged HEAD and list the zip's files, leaving out directory entries.
        $process = ($this->release_build)();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        expect(realpath(trim($process->getOutput())))->toBe(realpath($this->release_zip));
        $entries = array_values(array_filter(
            explode("\n", trim(($this->release_run)(['unzip', '-Z1', $this->release_zip]))),
            static fn (string $entry): bool => !str_ends_with($entry, '/'),
        ));
        sort($entries);

        // Dev files, bin/ and the Composer manifests stay out; vendor/ comes in.
        expect($entries)->toBe([
            'kntnt-ai-visibility/LICENSE',
            'kntnt-ai-visibility/README.md',
            'kntnt-ai-visibility/autoloader.php',
            'kntnt-ai-visibility/classes/Plugin.php',
            'kntnt-ai-visibility/install.php',
            'kntnt-ai-visibility/kntnt-ai-visibility.php',
            'kntnt-ai-visibility/languages/kntnt-ai-visibility.pot',
            'kntnt-ai-visibility/uninstall.php',
            'kntnt-ai-visibility/vendor/autoload.php',
            'kntnt-ai-visibility/vendor/composer/autoload_classmap.php',
        ]);
        $arguments = explode("\n", trim(file_get_contents($this->release_capture . '/composer-arguments')));
        expect($arguments)->toContain('install')->toContain('--no-dev')->toContain('--optimize-autoloader');
    });

    it('builds from the committed HEAD, not the working tree', function (): void {
        // Leave a modified tracked file and an untracked file inside a runtime directory.
        ($this->release_header)('1.2.3', 'WORKTREE');
        file_put_contents($this->release_repo . '/classes/Untracked.php', "<?php\n");

        // The zip carries the committed bytes, and the build says what it left out.
        $process = ($this->release_build)();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        expect($process->getErrorOutput())->toContain('uncommitted changes are not included');
        expect(($this->release_run)([
            'unzip', '-p', $this->release_zip, 'kntnt-ai-visibility/kntnt-ai-visibility.php',
        ]))->toContain('// COMMITTED')->not->toContain('WORKTREE');
        expect(($this->release_run)(['unzip', '-Z1', $this->release_zip]))->not->toContain('Untracked.php');
    });

    it('builds a tagged HEAD whose header matches the tag', function (string $tag): void {
        ($this->release_run)(['git', 'tag', $tag]);

        $process = ($this->release_build)();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        expect(file_exists($this->release_zip))->toBeTrue();
    })->with(['v-prefixed tag' => ['v1.2.3'], 'bare tag' => ['1.2.3']]);

    it('refuses a tagged HEAD whose header has another version', function (): void {
        ($this->release_run)(['git', 'tag', 'v1.2.4']);

        // A mismatch is a build failure, reported before anything is built.
        $process = ($this->release_build)();
        expect($process->getExitCode())->toBe(3);
        expect($process->getErrorOutput())->toContain('HEAD is tagged v1.2.4, but kntnt-ai-visibility.php has Version: 1.2.3.');
        expect(file_exists($this->release_zip))->toBeFalse();
        expect(file_exists($this->release_capture . '/composer-arguments'))->toBeFalse();
    });

    it('fails without a zip when the dependency install fails', function (): void {
        $process = ($this->release_build)(['KNTNT_RELEASE_COMPOSER_FAIL' => '1']);
        expect($process->getExitCode())->toBe(3);
        expect($process->getErrorOutput())->toContain('Preparing the plugin files failed.');
        expect(file_exists($this->release_zip))->toBeFalse();
    });

});
