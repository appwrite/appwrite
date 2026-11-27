<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Resolvers;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\DI\Container;
use Utopia\Http\Http;

use function Swoole\Coroutine\run;

final class ResolversTest extends TestCase
{
    private const float FIELD_SECONDS = 0.05;

    /**
     * run() leaves every runtime hook enabled and a reactor behind, so it gets a process of its own.
     */
    #[RunInSeparateProcess]
    public function testFieldsOfOneRequestNeverRunConcurrently(): void
    {
        $running = 0;
        $peak = 0;
        $completed = 0;

        $route = Http::get('/v1/mock/graphql/resolver-lock')
            ->hook(false)
            ->action(function () use (&$running, &$peak, &$completed): void {
                $running++;
                $peak = \max($peak, $running);
                Coroutine::sleep(self::FIELD_SECONDS);
                $running--;
                $completed++;
            });

        run(function () use ($route, &$completed): void {
            $server = new CoroutineServer();
            $http = new Http($server, 'UTC');
            $request = new Request(new SwooleRequest());
            $response = new Response(new SwooleResponse());

            $context = new Container($server->resources());
            $setRequestContext = require __DIR__ . '/../../../app/init/resources/request.php';
            $setRequestContext($context);
            $context->set('request', fn () => $request);
            $context->set('response', fn () => $response);
            $context->set('utopia', fn () => $http);
            Coroutine::getContext()[CoroutineServer::CONTEXT_KEY] = $context;

            $resolver = Resolvers::api($http, $route, Http::REQUEST_METHOD_GET);

            $resolver(null, [], null, null);
            $resolver(null, [], null, null);

            // The first field has finished and the second holds the lock when the third starts.
            Coroutine::sleep(self::FIELD_SECONDS * 1.5);
            $resolver(null, [], null, null);

            $deadline = \microtime(true) + 5;
            while ($completed < 3 && \microtime(true) < $deadline) {
                Coroutine::sleep(0.01);
            }
        });

        $this->assertSame(3, $completed, 'Every field resolves');
        $this->assertSame(1, $peak, 'Fields of one request run one at a time');
    }

    /**
     * The organization comes from the matched route. The outer /v1/graphql request resolves it
     * first (the CORS and key checks read it), so a field has to rebuild it, and the key built
     * from it, for its own route instead of reading the outer request's.
     */
    #[RunInSeparateProcess]
    public function testFieldsResolveTheOrganizationFromTheirOwnRoute(): void
    {
        $seen = [];

        $route = Http::get('/v1/mock/graphql/organization')
            ->hook(false)
            ->inject('team')
            ->inject('apiKey')
            ->action(function (string $team, string $apiKey) use (&$seen): void {
                $seen[] = [$team, $apiKey];
            });

        run(function () use ($route, &$seen): void {
            $server = new CoroutineServer();
            $http = new Http($server, 'UTC');
            $swooleRequest = new SwooleRequest();
            $swooleRequest->server = ['request_uri' => '/v1/graphql', 'request_method' => 'POST'];
            $request = new Request($swooleRequest);
            $response = new Response(new SwooleResponse());

            $context = new Container($server->resources());
            $setRequestContext = require __DIR__ . '/../../../app/init/resources/request.php';
            $setRequestContext($context);
            $context->set('request', fn () => $request);
            $context->set('response', fn () => $response);
            $context->set('utopia', fn () => $http);
            $context->set('team', fn (Request $request): string => 'team for ' . $request->getURI(), ['request']);
            $context->set('apiKey', fn (string $team): string => "key for $team", ['team']);
            Coroutine::getContext()[CoroutineServer::CONTEXT_KEY] = $context;

            $this->assertSame('key for team for /v1/graphql', $context->get('apiKey'));

            $resolver = Resolvers::api($http, $route, Http::REQUEST_METHOD_GET);
            $resolver(null, [], null, null);

            $deadline = \microtime(true) + 5;
            while ($seen === [] && \microtime(true) < $deadline) {
                Coroutine::sleep(0.01);
            }

            $this->assertSame('key for team for /v1/graphql', $context->get('apiKey'), 'The outer request keeps its own organization');
        });

        $this->assertSame([['team for /v1/mock/graphql/organization', 'key for team for /v1/mock/graphql/organization']], $seen);
    }
}
