<?php

declare(strict_types=1);

namespace Tests\Unit\Docker\Compose;

use Appwrite\Docker\Compose\Service;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    public function testPortsKeepHostBinding(): void
    {
        $service = new Service([
            'ports' => [
                '127.0.0.1:8080:80',
                '[::1]:8443:443',
                '2080:80',
                '${_APP_HTTP_PORT:-80}:80',
                9000,
            ],
        ]);

        $this->assertSame([
            '127.0.0.1:8080' => '80',
            '[::1]:8443' => '443',
            '2080' => '80',
            '${_APP_HTTP_PORT:-80}' => '80',
            '9000' => '',
        ], $service->getPorts());
    }
}
