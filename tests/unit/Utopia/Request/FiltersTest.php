<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request;

use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Tests\Unit\Utopia\Request\Filters\First;
use Tests\Unit\Utopia\Request\Filters\Second;
use Tests\Unit\Utopia\Request\Filters\ThrowingFilter;
use Utopia\Http\Route;

final class FiltersTest extends TestCase
{
    public function testAppliesFiltersInOrderToQueryString(): void
    {
        $request = $this->request();
        $request->setQueryString([
            'initial' => true,
            'first' => false,
        ]);

        Filters::apply($request, $this->singleMethodRoute(), [
            new First(),
            new Second(),
        ]);

        $this->assertSame([
            'initial' => true,
            'first' => true,
            'second' => true,
        ], $request->getParams());
    }

    public function testAppliesFiltersToPayloadForBodyRequests(): void
    {
        $request = $this->request();
        $request->setMethod(Request::METHOD_POST);
        $request->setPayload([
            'initial' => true,
            'first' => false,
        ]);

        Filters::apply($request, $this->singleMethodRoute(), [
            new First(),
            new Second(),
        ]);

        $this->assertSame([
            'initial' => true,
            'first' => true,
            'second' => true,
        ], $request->getParams());
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('methodMatchProvider')]
    public function testSelectsSdkMethodFromParameters(array $parameters, string $expected): void
    {
        $request = $this->request();
        $request->setQueryString($parameters);

        Filters::apply($request, $this->multiMethodRoute(), [
            new ModelFilter(),
        ]);

        $this->assertSame($expected, $request->getParams()['model']);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, string}>
     */
    public static function methodMatchProvider(): \Iterator
    {
        yield 'required parameter' => [['foo' => 'valueFoo'], 'namespace.methodA'];
        yield 'required and optional parameters' => [['foo' => 'valueFoo', 'bar' => 'valueBar'], 'namespace.methodA'];
        yield 'only optional parameter' => [['bar' => 'valueBar'], 'namespace.methodA'];
        yield 'second method parameter' => [['baz' => 'valueBaz'], 'namespace.methodB'];
        yield 'mixed unknown parameters' => [['foo' => 'valueFoo', 'baz' => 'valueBaz', 'extra' => 'unexpected'], 'unknown.unknown'];
    }

    public function testRouteIsNotStoredOnRequest(): void
    {
        $firstRequest = $this->request();
        $secondRequest = $this->request();
        $firstRequest->setQueryString([]);
        $secondRequest->setQueryString([]);

        Filters::apply($firstRequest, $this->singleMethodRoute('first'), [new ModelFilter()]);
        Filters::apply($secondRequest, $this->singleMethodRoute('second'), [new ModelFilter()]);

        $this->assertSame('namespace.first', $firstRequest->getParams()['model']);
        $this->assertSame('namespace.second', $secondRequest->getParams()['model']);
    }

    public function testDoesNotRunFiltersWithoutSdkLabel(): void
    {
        $filter = new ModelFilter();
        $request = $this->request();
        $request->setQueryString(['foo' => 'bar']);

        Filters::apply($request, new Route(Request::METHOD_GET, '/test'), [$filter]);

        $this->assertSame(['foo' => 'bar'], $request->getParams());
        $this->assertSame(0, $filter->calls);
    }

    public function testDoesNotMutateRequestWhenFilterThrows(): void
    {
        $filter = new ThrowingFilter(400, 'invalid input');
        $request = $this->request();
        $request->setQueryString(['foo' => 'bar']);

        $threw = false;
        try {
            Filters::apply($request, $this->singleMethodRoute(), [$filter]);
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertSame(400, $e->getCode());
            $this->assertSame('invalid input', $e->getMessage());
        }

        $this->assertTrue($threw);
        $this->assertSame(['foo' => 'bar'], $request->getParams());
        $this->assertSame(1, $filter->calls);
    }

    private function request(): Request
    {
        return new Request(new SwooleRequest());
    }

    private function singleMethodRoute(string $name = 'method'): Route
    {
        $route = new Route(Request::METHOD_GET, '/single');
        $route->label('sdk', new Method(
            namespace: 'namespace',
            group: 'group',
            name: $name,
            description: 'description',
            auth: [],
            responses: [],
        ));

        return $route;
    }

    private function multiMethodRoute(): Route
    {
        $route = new Route(Request::METHOD_GET, '/multi');

        $route->label('sdk', [
            new Method(
                namespace: 'namespace',
                group: 'group',
                name: 'methodA',
                description: 'desc',
                auth: [],
                responses: [],
                parameters: [
                    new Parameter('foo'),
                    new Parameter('bar', optional: true),
                ],
            ),
            new Method(
                namespace: 'namespace',
                group: 'group',
                name: 'methodB',
                description: 'desc',
                auth: [],
                responses: [],
                parameters: [
                    new Parameter('baz'),
                ],
            ),
        ]);

        return $route;
    }
}

final class ModelFilter extends Filter
{
    public int $calls = 0;

    public function parse(array $content, string $model): array
    {
        $this->calls++;
        $content['model'] = $model;

        return $content;
    }
}
