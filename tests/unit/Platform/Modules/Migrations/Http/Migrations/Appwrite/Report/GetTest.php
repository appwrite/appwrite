<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations\Http\Migrations\Appwrite\Report;

use Appwrite\AppwriteException;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Appwrite\Report\Get;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite as AppwriteSource;

final class GetTest extends TestCase
{
    private string|false $allowlist = false;

    protected function setUp(): void
    {
        $this->allowlist = \getenv('_APP_MIGRATIONS_ALLOWED_HOSTS');
        \putenv('_APP_MIGRATIONS_ALLOWED_HOSTS');
    }

    protected function tearDown(): void
    {
        \putenv($this->allowlist === false ? '_APP_MIGRATIONS_ALLOWED_HOSTS' : '_APP_MIGRATIONS_ALLOWED_HOSTS=' . $this->allowlist);
    }

    public function testSourceIsPinnedToTheCheckedEndpoint(): void
    {
        $action = new class () extends Get {
            public function source(string $endpoint): AppwriteSource
            {
                return $this->createSource($endpoint, 'project', 'key', fn () => throw new \Exception('No database'));
            }
        };

        $source = $action->source('http://127.0.0.1:' . $this->reservePort() . '/v1');

        $error = null;
        try {
            $source->report([Resource::TYPE_USER]);
        } catch (\Throwable $error) {
        }

        $this->assertInstanceOf(\Throwable::class, $error, 'Expected report() to be refused');
        $this->assertSame('Invalid `endpoint`: Value must be an http or https URL of a public host.', $error->getMessage());
        $this->assertNotInstanceOf(AppwriteException::class, $error->getPrevious());
    }

    private function reservePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, \strrpos($name, ':') + 1);
    }
}
