<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia;

use Appwrite\Utopia\Request;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;

final class RequestTest extends TestCase
{
    protected ?Request $request = null;

    public function setUp(): void
    {
        $this->request = new Request(new SwooleRequest());
    }

    public function testGetHeaderLineReturnsStringValue(): void
    {
        $this->request->addHeader('referer', 'https://example.com');

        $this->assertSame('https://example.com', $this->request->getHeaderLine('referer'));
    }

    public function testGetHeaderLineReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('', $this->request->getHeaderLine('referer'));
        $this->assertSame('fallback', $this->request->getHeaderLine('referer', 'fallback'));
    }

    public function testGetHeaderLineJoinsMultipleValues(): void
    {
        $swoole = new SwooleRequest();
        $swoole->header = ['referer' => ['https://a.example', 'https://b.example']];
        $request = new Request($swoole);

        $this->assertSame('https://a.example, https://b.example', $request->getHeaderLine('referer'));
    }
}
