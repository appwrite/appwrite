<?php

declare(strict_types=1);

namespace Swoole\Http {
    if (!\class_exists(Response::class, false)) {
        class Response
        {
        }
    }
}

namespace Swoole {
    if (!\class_exists(Timer::class, false)) {
        class Timer
        {
            public static function clearAll(): void
            {
            }
        }
    }
    if (!\class_exists(Event::class, false)) {
        class Event
        {
            public static function wait(): void
            {
            }
        }
    }
}

namespace Tests\Unit\Platform\Modules\Migrations\Appwrite\Report {

    use Appwrite\Extend\Exception;
    use Appwrite\Platform\Modules\Migrations\Http\Migrations\Appwrite\Report\Get;
    use Appwrite\Utopia\Response;
    use PHPUnit\Framework\TestCase;
    use Utopia\Migration\Sources\Appwrite as AppwriteSource;

    final class GetTest extends TestCase
    {
        public function testGetAppwriteReportThrowsExceptionWithPreviousAndMessage(): void
        {
            $action = new Get();
            $response = $this->createMock(Response::class);

            try {
                // Calling action with invalid/unreachable endpoint triggers an exception in report()
                $action->action(
                    resources: ['databases'],
                    endpoint: 'http://localhost:99999/v1',
                    projectID: 'dummy-project',
                    key: 'dummy-key',
                    response: $response,
                    getDatabasesDB: fn () => null
                );
                $this->fail('Expected Exception was not thrown');
            } catch (Exception $e) {
                $this->assertSame(Exception::MIGRATION_PROVIDER_ERROR, $e->getType());
                $this->assertNotNull($e->getPrevious());
                $this->assertStringStartsWith('Failed to generate migration report:', $e->getMessage());
            }
        }

        public function testGetAppwriteReportFallbackMessageWhenExceptionMessageIsEmpty(): void
        {
            $action = new class () extends Get {
                protected function getSource(string $projectID, string $endpoint, string $key, callable $getDatabasesDB): AppwriteSource
                {
                    throw new \Exception('');
                }
            };

            try {
                $action->action(
                    resources: ['databases'],
                    endpoint: 'http://localhost/v1',
                    projectID: 'dummy-project',
                    key: 'dummy-key',
                    response: $this->createMock(Response::class),
                    getDatabasesDB: fn () => null
                );
                $this->fail('Expected Exception was not thrown');
            } catch (Exception $e) {
                $this->assertSame(Exception::MIGRATION_PROVIDER_ERROR, $e->getType());
                $this->assertNotNull($e->getPrevious());
                $this->assertSame(
                    'Unable to connect to the migration source. Please verify your credentials and ensure the source is reachable from this server. Check for network restrictions such as firewalls, IP allowlists, or outbound connectivity limits.',
                    $e->getMessage()
                );
            }
        }
    }
}
