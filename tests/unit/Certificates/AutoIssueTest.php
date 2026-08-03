<?php

declare(strict_types=1);

namespace Tests\Unit\Certificates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;

final class AutoIssueTest extends TestCase
{
    #[DataProvider('policyProvider')]
    public function testPolicy(string|false $edition, string|false $option, string $hostname, bool $expected): void
    {
        $previousEdition = \getenv('_APP_EDITION');
        $previousOption = \getenv('_APP_ROUTER_AUTO_CERTIFICATES');

        $this->setEnvironment('_APP_EDITION', $edition);
        $this->setEnvironment('_APP_ROUTER_AUTO_CERTIFICATES', $option);

        try {
            global $container;

            $this->assertInstanceOf(Container::class, $container);

            $canAutoIssueCertificate = $container->get('canAutoIssueCertificate');

            $this->assertIsCallable($canAutoIssueCertificate);
            $this->assertSame($expected, $canAutoIssueCertificate($hostname));
        } finally {
            $this->setEnvironment('_APP_EDITION', $previousEdition);
            $this->setEnvironment('_APP_ROUTER_AUTO_CERTIFICATES', $previousOption);
        }
    }

    public static function policyProvider(): \Iterator
    {
        yield 'enabled by default on self-hosted' => [false, false, 'example.com', true];
        yield 'enabled explicitly on self-hosted' => ['self-hosted', 'enabled', 'example.com', true];
        yield 'disabled by operator' => ['self-hosted', 'disabled', 'example.com', false];
        yield 'disabled outside self-hosted' => ['cloud', 'enabled', 'example.com', false];
        yield 'disabled for local domain' => ['self-hosted', 'enabled', 'localhost', false];
    }

    private function setEnvironment(string $name, string|false $value): void
    {
        \putenv($value === false ? $name : "{$name}={$value}");
    }
}
