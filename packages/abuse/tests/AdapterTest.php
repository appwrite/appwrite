<?php

namespace Utopia\Abuse\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Adapter\TimeLimit\None;

final class AdapterTest extends TestCase
{
    public function testWithParamsReturnsNewInstance(): void
    {
        $adapter = new None('ip:{ip}', 1, 60);
        $clone = $adapter->withParams(['{ip}' => '127.0.0.1']);

        $this->assertNotSame($adapter, $clone);
        $this->assertSame('ip:{ip}', $adapter->key());
        $this->assertSame('ip:127.0.0.1', $clone->key());
    }

    public function testWithParamMergesIntoExistingParams(): void
    {
        $adapter = new None('{ip}:{url}', 1, 60)
            ->withParams(['{ip}' => '127.0.0.1'])
            ->withParam('{url}', '/v1/account');

        $this->assertSame('127.0.0.1:/v1/account', $adapter->key());
        $this->assertSame('127.0.0.1:/v1/health', $adapter->withParam('{url}', '/v1/health')->key());
    }

    public function testLongestPlaceholderWins(): void
    {
        $adapter = new None('{param-id}|{param-idx}', 1, 60)->withParams([
            '{param-id}' => 'a',
            '{param-idx}' => 'b',
        ]);

        $this->assertSame('a|b', $adapter->key());
    }

    public function testValuesAreNotSubstitutedAgain(): void
    {
        $adapter = new None('{a}|{b}', 1, 60)->withParams([
            '{a}' => '{b}',
            '{b}' => 'x',
        ]);

        $this->assertSame('{b}|x', $adapter->key());
    }

    public function testPlaceholderWithoutParamStaysLiteral(): void
    {
        $adapter = new None('{ip}:{url}', 1, 60)->withParam('{ip}', '127.0.0.1');

        $this->assertSame('127.0.0.1:{url}', $adapter->key());
    }
}
