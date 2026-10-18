<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    /**
     * @param  array<string, string>  $environment
     */
    private function withEnvironment(array $environment, callable $test): void
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
    private function s3(string $device, string $endpoint): array
    {
        return [
            '_APP_STORAGE_DEVICE' => $device,
            '_APP_STORAGE_S3_ENDPOINT' => $endpoint,
            '_APP_STORAGE_S3_ACCESS_KEY' => 'compatibility-access',
            '_APP_STORAGE_S3_SECRET' => 'compatibility-secret',
            '_APP_STORAGE_S3_BUCKET' => 'compatibility',
            '_APP_STORAGE_S3_REGION' => 'us-east-1',
            '_APP_CONNECTIONS_STORAGE' => '',
            '_APP_COMPUTE_BUILD_COMPRESSION' => 'gzip',
        ];
    }

    #[DataProvider('devices')]
    public function testBuildAndCachePathsUseTheConfiguredDevice(string $device, string $endpoint, string $prefix): void
    {
        $this->withEnvironment($this->s3($device, $endpoint), function () use ($device, $prefix): void {
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
        $this->withEnvironment($this->s3('s3', 'http://storage.invalid'), function (): void {
            $device = getDevice('/storage/uploads/app-project');
            $this->assertSame('compatibility/storage/uploads/app-project', $device->getRoot());

            $this->expectException(\InvalidArgumentException::class);
            $device->listFiles('outside/x');
        });
    }
}
