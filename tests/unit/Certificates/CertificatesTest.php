<?php

declare(strict_types=1);

namespace Tests\Unit\Certificates;

use Appwrite\Certificates\Certificates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class CertificatesTest extends TestCase
{
    #[DataProvider('autoIssueProvider')]
    public function testIsAutoIssueEnabled(string|false $edition, string|false $option, string $hostname, string $owner, bool $expected): void
    {
        $previousEdition = \getenv('_APP_EDITION');
        $previousOption = \getenv('_APP_ROUTER_AUTO_CERTIFICATES');

        $this->setEnvironment('_APP_EDITION', $edition);
        $this->setEnvironment('_APP_ROUTER_AUTO_CERTIFICATES', $option);

        try {
            $certificates = new Certificates(new Document([
                'domain' => $hostname,
                'owner' => $owner,
            ]));

            $this->assertSame($expected, $certificates->isAutoIssueEnabled());
        } finally {
            $this->setEnvironment('_APP_EDITION', $previousEdition);
            $this->setEnvironment('_APP_ROUTER_AUTO_CERTIFICATES', $previousOption);
        }
    }

    public static function autoIssueProvider(): \Iterator
    {
        yield 'enabled by default on self-hosted' => [false, false, 'example.com', 'Appwrite', true];
        yield 'enabled explicitly on self-hosted' => ['self-hosted', 'enabled', 'example.com', 'Appwrite', true];
        yield 'disabled by operator' => ['self-hosted', 'disabled', 'example.com', 'Appwrite', false];
        yield 'disabled outside self-hosted' => ['cloud', 'enabled', 'example.com', 'Appwrite', false];
        yield 'disabled for local domain' => ['self-hosted', 'enabled', 'localhost', 'Appwrite', false];
        yield 'disabled for non-Appwrite owner' => ['self-hosted', 'enabled', 'example.com', '', false];
    }

    private function setEnvironment(string $name, string|false $value): void
    {
        \putenv($value === false ? $name : "{$name}={$value}");
    }
}
