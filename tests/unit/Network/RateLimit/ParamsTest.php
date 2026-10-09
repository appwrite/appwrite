<?php

declare(strict_types=1);

namespace Tests\Unit\Network\RateLimit;

use Appwrite\Network\RateLimit\Params;
use Appwrite\Utopia\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Database\Document;
use Utopia\Http\Route;

final class ParamsTest extends TestCase
{
    /**
     * @return \Iterator<string, array{mixed}>
     */
    public static function emptyValues(): \Iterator
    {
        yield 'zero string' => ['0'];
        yield 'zero' => [0];
        yield 'false' => [false];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'empty list' => [[]];
    }

    #[DataProvider('emptyValues')]
    public function testAnEmptyParamKeepsItsPlaceholderAsOnMain(mixed $value): void
    {
        $params = Params::of($this->request(['userId' => $value]), new Route('POST', '/v1/account'), new Document(['$id' => 'project']), new Document([]));

        $this->assertArrayNotHasKey('{param-userId}', $params, 'Main left the placeholder of an empty() param literal, so its rate-limit bucket must not change.');
    }

    /**
     * @return \Iterator<string, array{mixed, string}>
     */
    public static function filledValues(): \Iterator
    {
        yield 'string' => ['user', 'user'];
        yield 'integer' => [7, '7'];
        yield 'true' => [true, '1'];
        yield 'list' => [['a', 'b'], '["a","b"]'];
    }

    #[DataProvider('filledValues')]
    public function testAFilledParamFillsItsPlaceholder(mixed $value, string $expected): void
    {
        $params = Params::of($this->request(['userId' => $value]), new Route('POST', '/v1/account'), new Document(['$id' => 'project']), new Document([]));

        $this->assertSame($expected, $params['{param-userId}']);
        $this->assertSame('project', $params['{projectId}']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload): Request
    {
        $swoole = new SwooleRequest();
        $swoole->server = ['request_method' => 'POST', 'remote_addr' => '203.0.113.10'];
        $swoole->header = ['host' => 'localhost', 'content-type' => 'application/json'];
        $request = new Request($swoole);
        $request->setPayload($payload);

        return $request;
    }
}
