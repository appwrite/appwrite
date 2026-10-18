<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Storage\Device;

final class StorageTest extends TestCase
{
    /**
     * @param  array<string, string>  $environment
     * @param  \Closure(): void  $test
     */
    private function withEnvironment(array $environment, \Closure $test): void
    {
        $previous = [];
        foreach ($environment as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            $test();
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $device, string $endpoint, string $bucket = 'compatibility', string $pathStyle = ''): array
    {
        return [
            '_APP_STORAGE_DEVICE' => $device,
            '_APP_STORAGE_S3_ENDPOINT' => $endpoint,
            '_APP_STORAGE_S3_ACCESS_KEY' => 'compatibility-access',
            '_APP_STORAGE_S3_SECRET' => 'compatibility-secret',
            '_APP_STORAGE_S3_BUCKET' => $bucket,
            '_APP_STORAGE_S3_REGION' => 'us-east-1',
            '_APP_STORAGE_S3_PATH_STYLE' => $pathStyle,
            '_APP_CONNECTIONS_STORAGE' => '',
            '_APP_COMPUTE_BUILD_COMPRESSION' => 'gzip',
        ];
    }

    /**
     * Whether the device turns the listing down itself; the endpoints are unresolvable, so anything else is a failed request.
     */
    private function refuses(Device $device, string $prefix): bool
    {
        try {
            $device->listFiles($prefix);
        } catch (\InvalidArgumentException) {
            return true;
        } catch (\Throwable) {
        }

        return false;
    }

    #[DataProvider('devices')]
    public function testBuildAndCachePathsUseTheConfiguredDevice(string $device, string $endpoint, string $prefix): void
    {
        $this->withEnvironment($this->environment($device, $endpoint), function () use ($device, $prefix): void {
            $this->assertSame($prefix . '/storage/builds/app-project/deployment/code.tar.gz', Deployments::buildPath('project', 'deployment'));
            $this->assertSame($prefix . '/storage/builds/app-project/cache/cache-key.sqfs', Deployments::cachePath('project', 'cache-key'));
            if ($device === 's3') {
                $this->assertSame('s3://compatibility/storage/builds/app-project/deployment/code.tar.gz', Storage::output('project', 'deployment'));
            }
        });
    }

    public static function devices(): iterable
    {
        yield 'local' => ['local', '', ''];
        yield 'virtual-host S3' => ['s3', '', ''];
        yield 'path-style S3' => ['s3', 'http://storage.invalid', 'compatibility'];
    }

    public function testPathStyleDeviceKnowsItsBucket(): void
    {
        $this->withEnvironment($this->environment('s3', 'http://storage.invalid'), function (): void {
            $device = getDevice('/storage/uploads/app-project');

            $this->assertSame('compatibility/storage/uploads/app-project', $device->getRoot());
            $this->assertTrue($this->refuses($device, 'outside/x'));
            $this->assertTrue($this->refuses($device, 'compatibility/../x'));
        });
    }

    public function testPathStyleSettingReachesTheDevice(): void
    {
        // Inferred, a host beginning with the bucket name addresses the bucket.
        $this->withEnvironment($this->environment('s3', 'http://compatibility.storage.invalid', pathStyle: 'enabled'), function (): void {
            $this->assertTrue($this->refuses(getDevice('/storage/uploads/app-project'), 'outside/x'));
        });
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bucketsWithSlashes(): iterable
    {
        yield 'bucket with a key prefix' => ['compatibility/prefix', 'compatibility/prefix/storage/uploads/app-project'];
        yield 'bucket from a DSN path' => ['compatibility/', 'compatibility//storage/uploads/app-project'];
    }

    #[DataProvider('bucketsWithSlashes')]
    public function testBucketHoldingSlashesKeepsItsRoot(string $bucket, string $root): void
    {
        $this->withEnvironment($this->environment('s3', 'http://storage.invalid', $bucket), function () use ($root): void {
            $device = getDevice('/storage/uploads/app-project');

            $this->assertSame($root, $device->getRoot());
            $this->assertTrue($this->refuses($device, 'outside/x'));
        });
    }
}
