<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Bitbucket;
use Utopia\VCS\Adapter\Git\Entire;
use Utopia\VCS\Adapter\Git\Forgejo;
use Utopia\VCS\Adapter\Git\Gitea;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Adapter\Git\GitLab;
use Utopia\VCS\Adapter\Git\Gogs;
use Utopia\VCS\Adapter\Git\Origin;

final class AdapterTest extends TestCase
{
    /**
     * @return \Iterator<string, array{class-string<Git>}>
     */
    public static function adapters(): \Iterator
    {
        yield 'bitbucket' => [Bitbucket::class];
        yield 'entire' => [Entire::class];
        yield 'forgejo' => [Forgejo::class];
        yield 'gitea' => [Gitea::class];
        yield 'github' => [GitHub::class];
        yield 'gitlab' => [GitLab::class];
        yield 'gogs' => [Gogs::class];
        yield 'origin' => [Origin::class];
    }

    /**
     * @param class-string<Git> $class
     */
    #[DataProvider('adapters')]
    public function testVerifiesCertificatesByDefault(string $class): void
    {
        $adapter = new $class(new Cache(new None()));

        $this->assertFalse($adapter->isSelfSigned());
    }

    /**
     * @param class-string<Git> $class
     */
    #[DataProvider('adapters')]
    public function testAcceptsSelfSignedCertificatesWhenOptedIn(string $class): void
    {
        $adapter = new $class(new Cache(new None()));

        $adapter->setSelfSigned(true);
        $this->assertTrue($adapter->isSelfSigned());

        $adapter->setSelfSigned(false);
        $this->assertFalse($adapter->isSelfSigned());
    }
}
