<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Throwable;
use Utopia\Bus\Bus;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Http\Adapter;
use Utopia\Http\Http;
use Utopia\Servers\Hook;
use Utopia\VCS\Exception\OwnerNotFound;

final class ErrorHookTest extends TestCase
{
    public function testMissingVcsInstallationRespondsInstallationNotFound(): void
    {
        $response = $this->respond(new OwnerNotFound('Installation not found'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(Exception::INSTALLATION_NOT_FOUND, $response->getPayload()['type'] ?? null);
    }

    public function testUnknownErrorRespondsServerError(): void
    {
        $response = $this->respond(new RuntimeException('Unexpected'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(Exception::GENERAL_UNKNOWN, $response->getPayload()['type'] ?? null);
        $this->assertSame('Server Error', $response->getPayload()['message'] ?? null);
    }

    private function respond(Throwable $error): Response
    {
        require_once __DIR__ . '/../../../app/controllers/general.php';

        $swooleRequest = new SwooleRequest();
        $swooleRequest->server = [
            'request_method' => Http::REQUEST_METHOD_GET,
            'request_uri' => '/v1/vcs/github/installations/installation/providerRepositories/repository',
        ];
        $swooleRequest->header = [];

        $response = new class (new SwooleResponse()) extends Response {
            public function end(?string $content = null): void
            {
            }

            public function write(string $content): bool
            {
                return true;
            }

            public function sendHeader(string $key, array $value): void
            {
            }

            protected function sendStatus(int $statusCode): void
            {
            }
        };

        $action = $this->errorHook()->getAction();
        $action(
            $error,
            new Http($this->server(), 'UTC'),
            new Request($swooleRequest),
            $response,
            new Document(['$id' => 'console']),
            new Bus(),
            new Authorization(),
            [],
        );

        return $response;
    }

    private function errorHook(): Hook
    {
        /** @var list<Hook> $hooks */
        $hooks = (new ReflectionProperty(Http::class, 'errors'))->getValue();

        foreach ($hooks as $hook) {
            if (\in_array('localeCodes', $hook->getDependencies(), true)) {
                return $hook;
            }
        }

        $this->fail('The API error hook is not registered.');
    }

    private function server(): Adapter
    {
        return new class () extends Adapter {
            private Container $resources;

            public function __construct()
            {
                $this->resources = new Container();
            }

            public function onStart(callable $callback): void
            {
            }

            public function onRequest(callable $callback): void
            {
            }

            public function start(): void
            {
            }

            public function resources(): Container
            {
                return $this->resources;
            }

            public function context(): Container
            {
                return $this->resources;
            }
        };
    }
}
