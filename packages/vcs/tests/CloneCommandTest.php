<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Command;
use Utopia\Console;
use Utopia\VCS\Adapter\Git;

/**
 * Runs the clone command every Git adapter shares against a local repository
 * and checks what lands on disk.
 */
final class CloneCommandTest extends TestCase
{
    /**
     * A directory name that, split on its tabs, reads as GNU tar options, and
     * that carries shell syntax besides.
     */
    private const string HOSTILE_DIRECTORY = "src\t--checkpoint=1\t--checkpoint-action=exec=touch pwned;touch pwned\$(touch pwned)`touch pwned`";

    private string $workspace;

    private string $repository;

    private string $directory;

    private string $cwd;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/utopia-vcs-clone-' . uniqid();
        $this->repository = $this->workspace . '/remote.git';
        $this->directory = $this->workspace . '/clone';
        $source = $this->workspace . '/source';

        // The clone command sets init.defaultBranch globally; keep that off the host
        putenv('GIT_CONFIG_GLOBAL=' . $this->workspace . '/gitconfig');

        $files = [
            'README.md' => '# Root',
            'docs/guide.md' => '# Guide',
            'astro/starter/index.md' => '# Starter',
            '0/zero.md' => '# Zero',
            self::HOSTILE_DIRECTORY . '/index.md' => '# Hostile',
        ];
        foreach ($files as $path => $content) {
            $file = $source . '/' . $path;
            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
        }

        $this->execute(new Command('git')->argument('init')->flag('-q')->option('-b', 'main')->argument($source));
        $this->execute(new Command('git')->option('-C', $source)->argument('add')->argument('.'));
        $this->execute(new Command('git')->option('-C', $source)->option('-c', 'user.name=Test')->option('-c', 'user.email=test@example.com')->argument('commit')->flag('-q')->option('-m', 'Initial'));
        $this->execute(new Command('git')->argument('clone')->flag('-q')->flag('--bare')->argument($source)->argument($this->repository));

        // Anything the hostile name managed to run would land here
        $cwd = getcwd();
        $this->cwd = $cwd === false ? '/' : $cwd;
        chdir($this->workspace);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        putenv('GIT_CONFIG_GLOBAL');
        $this->execute(new Command('rm')->flag('-rf')->argument($this->workspace));
    }

    #[DataProvider('rootDirectories')]
    public function testCloneChecksOutTheRootDirectory(string $rootDirectory, string $file): void
    {
        $this->assertSame(0, $this->clone($rootDirectory));

        $this->assertFileExists($this->directory . '/' . $file);
        if ($file !== 'README.md') {
            $this->assertFileDoesNotExist($this->directory . '/README.md');
        }
    }

    /**
     * Git matches a sparse-checkout pattern gitignore-style, so a './' prefix
     * looks for a directory literally named '.' and checks out nothing.
     */
    public static function rootDirectories(): \Iterator
    {
        yield 'repository root' => ['', 'README.md'];
        yield 'dot' => ['.', 'README.md'];
        yield 'dot slash' => ['./', 'README.md'];
        yield 'slash' => ['/', 'README.md'];
        yield 'bare' => ['docs', 'docs/guide.md'];
        yield 'trailing slash' => ['docs/', 'docs/guide.md'];
        yield 'dot slash prefix' => ['./docs', 'docs/guide.md'];
        yield 'dot slash prefix and trailing slash' => ['./docs/', 'docs/guide.md'];
        yield 'nested' => ['./astro/starter', 'astro/starter/index.md'];
        // A directory named '0' is a real path, not a root sentinel.
        yield 'zero' => ['0', '0/zero.md'];
    }

    public function testCloneTreatsTheRootDirectoryAsData(): void
    {
        $this->assertSame(0, $this->clone(self::HOSTILE_DIRECTORY));

        $this->assertFileExists($this->directory . '/' . self::HOSTILE_DIRECTORY . '/index.md');
        $this->assertFileDoesNotExist($this->directory . '/README.md');
        $this->assertFileDoesNotExist($this->workspace . '/pwned');
        $this->assertFileDoesNotExist($this->directory . '/pwned');
    }

    private function clone(string $rootDirectory): int
    {
        $adapter = new LocalGit($this->repository);

        return $this->execute($adapter->generateCloneCommand('owner', 'repository', 'main', Git::CLONE_TYPE_BRANCH, $this->directory, $rootDirectory));
    }

    private function execute(Command $command): int
    {
        $stdout = '';
        $stderr = '';

        return Console::execute($command, '', $stdout, $stderr);
    }
}
