<?php

declare(strict_types=1);

namespace Utopia\Tests\Psr7;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Utopia\Psr7\ServerRequest;
use Utopia\Psr7\Stream;
use Utopia\Psr7\UploadedFile;
use Utopia\Psr7\Uri;

final class ServerRequestTest extends TestCase
{
    public function testServerRequestImplementsPsrInterface(): void
    {
        $request = new ServerRequest\Factory()->createServerRequest('post', 'https://example.com/users?active=1', [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $this->assertInstanceOf(ServerRequestFactoryInterface::class, new ServerRequest\Factory());
        $this->assertInstanceOf(ServerRequestInterface::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/users?active=1', $request->getRequestTarget());
        $this->assertSame('example.com', $request->getHeaderLine('Host'));
        $this->assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->getServerParams());
    }

    public function testServerRequestPsrMutatorsAreImmutable(): void
    {
        $request = new ServerRequest('GET', Uri::parse('/users'), body: new Stream('body'));

        $changed = $request
            ->withMethod('PATCH')
            ->withQueryParams(['active' => '1'])
            ->withCookieParams(['session' => 'abc'])
            ->withParsedBody(['name' => 'Ada'])
            ->withAttribute('route', '/users/:id')
            ->withHeader('Accept', 'application/json');

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('PATCH', $changed->getMethod());
        $this->assertSame([], $request->getQueryParams());
        $this->assertSame(['active' => '1'], $changed->getQueryParams());
        $this->assertSame(['session' => 'abc'], $changed->getCookieParams());
        $this->assertSame(['name' => 'Ada'], $changed->getParsedBody());
        $this->assertSame('/users/:id', $changed->getAttribute('route'));
        $this->assertSame('application/json', $changed->getHeaderLine('Accept'));
    }

    public function testUploadedFilesMustBePsrUploadedFileTree(): void
    {
        $request = new ServerRequest('POST', Uri::parse('/upload'));
        $uploaded = new UploadedFile('/tmp/missing', 0, UPLOAD_ERR_NO_FILE, 'avatar.png', 'image/png');

        $changed = $request->withUploadedFiles(['avatar' => $uploaded]);

        $this->assertSame(['avatar' => $uploaded], $changed->getUploadedFiles());

        $this->expectException(InvalidArgumentException::class);

        $request->withUploadedFiles(['avatar' => ['not-a-file']]);
    }
}
