<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Gogs;

final class GogsTest extends GiteaBase
{
    protected static string $defaultBranch = 'master';

    protected static string $eventHeader = 'x-gogs-event';
    protected static string $signatureHeader = 'x-gogs-signature';

    protected function createAdapter(): Gogs
    {
        return new Gogs(new Cache(new None()));
    }

    /**
     * @return \Iterator<string, array{string, string, string}>
     */
    public static function unsafePathProvider(): \Iterator
    {
        yield 'parent directory' => ['../escape.txt', '', 'escapes the repository'];
        yield 'parent directory on a branch' => ['docs/../../escape.txt', 'feature', 'escapes the repository'];
        yield 'git metadata' => ['.git/config', '', 'names repository metadata'];
        yield 'git metadata on a branch' => ['nested/.GIT/hooks/pre-commit', 'feature', 'names repository metadata'];
        yield 'no file' => ['./.', 'feature', 'names no file'];
    }

    #[DataProvider('unsafePathProvider')]
    public function testCreateFileRejectsUnsafePath(string $path, string $branch, string $message): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($message);
        $this->createAdapter()->createFile('owner', 'repo', $path, 'content', 'Add file', $branch);
    }
}
