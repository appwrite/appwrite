<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request;

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\CacheIdentifier;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;

final class CacheIdentifierTest extends TestCase
{
    public function testCreatesStableIdentifierFromRequest(): void
    {
        $request = new Request(new SwooleRequest());
        $request->setURI('/v1/avatars/image');
        $request->setQueryString([
            'size' => '128',
            'name' => 'Ada',
        ]);
        $request->addHeader('x-appwrite-project', 'console');

        $expectedParams = [
            'name' => 'Ada',
            'project' => 'console',
            'size' => '128',
        ];

        $this->assertSame(
            \md5('/v1/avatars/image*' . \serialize($expectedParams) . '*' . APP_CACHE_BUSTER),
            CacheIdentifier::fromRequest($request)->toString()
        );
    }

    public function testFiltersParamsFromAllowedParams(): void
    {
        $request = new Request(new SwooleRequest());
        $request->setURI('/v1/storage/buckets/bucket/files/file/preview');
        $request->setQueryString([
            'height' => 100,
            'ignored' => 'value',
            'width' => 200,
        ]);
        $request->addHeader('x-appwrite-project', 'project-id');

        $expectedParams = [
            'height' => 100,
            'project' => 'project-id',
            'width' => 200,
        ];

        $this->assertSame(
            \md5('/v1/storage/buckets/bucket/files/file/preview*' . \serialize($expectedParams) . '*' . APP_CACHE_BUSTER),
            CacheIdentifier::fromRequest($request, ['width', 'height', 'project'])->toString()
        );
    }

    public function testKeepsProjectParamOverHeader(): void
    {
        $request = new Request(new SwooleRequest());
        $request->setURI('/v1/avatars/image');
        $request->setQueryString([
            'project' => 'query-project',
        ]);
        $request->addHeader('x-appwrite-project', 'header-project');

        $expectedParams = [
            'project' => 'query-project',
        ];

        $identifier = CacheIdentifier::fromRequest($request);

        $this->assertSame(
            \md5('/v1/avatars/image*' . \serialize($expectedParams) . '*' . APP_CACHE_BUSTER),
            $identifier->toString()
        );
        $this->assertSame($identifier->toString(), (string) $identifier);
    }
}
