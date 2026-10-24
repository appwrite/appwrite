<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\VCS;

use Appwrite\Utopia\VCS\Adapter\Git\GitHub;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

final class GitHubTest extends TestCase
{
    public function testVerifiesProviderCertificates(): void
    {
        $github = new class (new Cache(new None())) extends GitHub {
            public function acceptsSelfSigned(): bool
            {
                return $this->selfSigned;
            }
        };

        $this->assertFalse($github->acceptsSelfSigned());
    }
}
