<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Listing;
use Utopia\Distribution\Release;
use Utopia\Distribution\Store;
use Utopia\Distribution\Upload;
use Utopia\Psr7\Response;
use Utopia\Storage\Device\Local;

final class StoreTest extends TestCase
{
    /**
     * @return iterable<string, array{string, \Closure(Store): void}>
     */
    public static function operations(): iterable
    {
        yield 'upload' => ['upload', static function (Store $store): void {
            $store->upload(self::upload(), null);
        }];
        yield 'upload with progress' => ['upload', static function (Store $store): void {
            $store->upload(self::upload(), ['parts' => [1]], new Checkpoints());
        }];
        yield 'promote' => ['promote', static function (Store $store): void {
            $store->promote('io.example.app', '42');
        }];
        yield 'processed' => ['processed', static function (Store $store): void {
            $store->processed(['id' => 'upload']);
        }];
        yield 'release' => ['release', static function (Store $store): void {
            $store->release(new Release('io.example.app', Release::TRACK_PRODUCTION, '1.0.0', '42'), ['id' => 'upload']);
        }];
        yield 'state' => ['state', static function (Store $store): void {
            $store->state(['id' => 'upload'], Release::TRACK_PRODUCTION);
        }];
        yield 'rollout' => ['rollout', static function (Store $store): void {
            $store->rollout(['id' => 'upload'], Release::TRACK_PRODUCTION, 0.5);
        }];
        yield 'halt' => ['halt', static function (Store $store): void {
            $store->halt(['id' => 'upload'], Release::TRACK_PRODUCTION);
        }];
        yield 'resume' => ['resume', static function (Store $store): void {
            $store->resume(['id' => 'upload'], Release::TRACK_PRODUCTION);
        }];
        yield 'withdraw' => ['withdraw', static function (Store $store): void {
            $store->withdraw(['id' => 'upload']);
        }];
        yield 'listing' => ['listing', static function (Store $store): void {
            $store->listing(new Listing('io.example.app', 'en-US', [Listing::FIELD_NAME => 'Example'], device: self::device()), []);
        }];
    }

    /**
     * @param \Closure(Store): void $call
     */
    #[DataProvider('operations')]
    public function testUnsupportedOperationThrowsInvalid(string $operation, \Closure $call): void
    {
        $transport = new Transport();
        $store = new Unsupported($transport, 'https://store.test');

        try {
            $call($store);
            $this->fail("{$operation} should throw");
        } catch (Invalid $invalid) {
            $this->assertSame("{$operation} is not supported by unsupported", $invalid->getMessage());
            $this->assertSame([], $invalid->getIssues());
        }

        $this->assertSame([], $transport->requests());
    }

    public function testClientExceptionSurfacesAsUnavailable(): void
    {
        $failure = new Failure('connection refused');
        $store = new Unsupported(new Transport($failure), 'https://store.test');

        try {
            $store->account();
            $this->fail('account should throw');
        } catch (Unavailable $unavailable) {
            $this->assertSame($failure, $unavailable->getPrevious());
            $this->assertStringContainsString('connection refused', $unavailable->getMessage());
        }
    }

    public function testEndpointTrailingSlashIsTrimmed(): void
    {
        $transport = new Transport(new Response(200));
        $store = new Unsupported($transport, 'https://store.test/api/');

        $store->account();

        $requests = $transport->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('https://store.test/api/account', (string) $requests[0]->getUri());
    }

    private static function upload(): Upload
    {
        return new Upload(self::device(), 'builds/app.ipa', 'App.ipa', 1024, 'io.example.app', '1.0.0', '42', Upload::PLATFORM_IOS);
    }

    private static function device(): Local
    {
        return new Local(sys_get_temp_dir() . '/distribution');
    }
}
