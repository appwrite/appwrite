<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Console\Command;
use Utopia\Console\Console;
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

    private string|false $gitConfigGlobal;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/utopia-vcs-clone-' . uniqid();
        $this->repository = $this->workspace . '/remote.git';
        $this->directory = $this->workspace . '/clone';
        $source = $this->workspace . '/source';

        // The clone command sets init.defaultBranch globally; keep that off the host
        $this->gitConfigGlobal = getenv('GIT_CONFIG_GLOBAL');
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
        putenv($this->gitConfigGlobal === false ? 'GIT_CONFIG_GLOBAL' : 'GIT_CONFIG_GLOBAL=' . $this->gitConfigGlobal);
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

    public function testCloneChecksOutATagStartingWithADash(): void
    {
        // git tag refuses the name, but it is a valid ref a provider can hold
        $this->execute(new Command('git')->option('-C', $this->repository)->argument('update-ref')->argument('refs/tags/-release')->argument('main'));

        $this->assertSame(0, $this->clone('', '-release', Git::CLONE_TYPE_TAG));

        $this->assertFileExists($this->directory . '/README.md');
    }

    /**
     * `git ls-remote` lists tags in refname order, and the lookup keeps the
     * last line. 0.1.10 sorts before 0.1.2; the annotated 0.1.2 is the tag
     * that line names. A namespaced pattern keeps the full name after
     * `refs/tags/`, so `release/0.1.*` resolves to `release/0.1.2`.
     */
    public function testCloneChecksOutATagPattern(): void
    {
        $this->tag('0.1.0', "tag-0.1.0\n");
        $this->tag('0.1.10', "tag-0.1.10\n");
        $this->tag('0.1.2', "tag-0.1.2\n", annotated: true);
        $this->tag('0.2.0', "tag-0.2.0\n");

        $this->assertSame(0, $this->clone('', '0.1.*', Git::CLONE_TYPE_TAG));

        $this->assertSame("tag-0.1.2\n", $this->read($this->directory . '/README.md'));

        $this->tag('release/0.1.0', "tag-release-0.1.0\n");
        $this->tag('release/0.1.10', "tag-release-0.1.10\n");
        $this->tag('release/0.1.2', "tag-release-0.1.2\n", annotated: true);
        $this->tag('release/0.2.0', "tag-release-0.2.0\n");

        $this->execute(new Command('rm')->flag('-rf')->argument($this->directory));
        $this->assertSame(0, $this->clone('', 'release/0.1.*', Git::CLONE_TYPE_TAG));

        $this->assertSame("tag-release-0.1.2\n", $this->read($this->directory . '/README.md'));
    }

    public function testCloneChecksOutAnExactTag(): void
    {
        $this->tag('1.2.3', "tag-1.2.3\n");
        $this->tag('1.2.4', "tag-1.2.4\n");

        $this->assertSame(0, $this->clone('', '1.2.3', Git::CLONE_TYPE_TAG));

        $this->assertSame("tag-1.2.3\n", $this->read($this->directory . '/README.md'));
    }

    public function testCloneFailsWhenNoTagMatches(): void
    {
        $this->tag('0.1.0', "tag-0.1.0\n");

        $stderr = '';
        $this->assertNotSame(0, $this->clone('', '9.9.*', Git::CLONE_TYPE_TAG, $stderr));
        $this->assertStringContainsString('fatal: no tag matching 9.9.*', $stderr);
    }

    private function tag(string $name, string $content, bool $annotated = false): void
    {
        $work = $this->workspace . '/work';
        if (!is_dir($work . '/.git')) {
            $this->assertSame(0, $this->execute(new Command('git')->argument('clone')->flag('-q')->argument($this->repository)->argument($work)));
        }

        file_put_contents($work . '/README.md', $content);
        $this->assertSame(0, $this->execute(new Command('git')->option('-C', $work)->argument('add')->argument('README.md')));
        $this->assertSame(0, $this->execute(new Command('git')->option('-C', $work)->option('-c', 'user.name=Test')->option('-c', 'user.email=test@example.com')->argument('commit')->flag('-q')->option('-m', $name)));

        $tag = new Command('git')->option('-C', $work)->option('-c', 'user.name=Test')->option('-c', 'user.email=test@example.com')->argument('tag');
        if ($annotated) {
            $tag->flag('-a')->option('-m', $name);
        }
        $this->assertSame(0, $this->execute($tag->argument($name)));
        $this->assertSame(0, $this->execute(new Command('git')->option('-C', $work)->argument('push')->flag('-q')->argument('origin')->argument('refs/tags/' . $name)));
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        $this->assertIsString($content);

        return $content;
    }

    private function clone(string $rootDirectory, string $version = 'main', string $versionType = Git::CLONE_TYPE_BRANCH, string &$stderr = ''): int
    {
        $adapter = new LocalGit($this->repository);

        return $this->execute($adapter->generateCloneCommand('owner', 'repository', $version, $versionType, $this->directory, $rootDirectory), $stderr);
    }

    private function execute(Command $command, string &$stderr = ''): int
    {
        $stdout = '';
        $stderr = '';

        return Console::execute($command, '', $stdout, $stderr);
    }
}
