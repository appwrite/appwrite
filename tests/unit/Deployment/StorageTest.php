<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    #[DataProvider('devices')]
    public function testBuildAndCachePathsUseTheConfiguredDevice(string $device, string $endpoint, string $prefix): void
    {
        $environment = [
            '_APP_STORAGE_DEVICE' => $device,
            '_APP_STORAGE_S3_ENDPOINT' => $endpoint,
            '_APP_STORAGE_S3_ACCESS_KEY' => 'compatibility-access',
            '_APP_STORAGE_S3_SECRET' => 'compatibility-secret',
            '_APP_STORAGE_S3_BUCKET' => 'compatibility',
            '_APP_STORAGE_S3_REGION' => 'us-east-1',
            '_APP_CONNECTIONS_STORAGE' => '',
            '_APP_COMPUTE_BUILD_COMPRESSION' => 'gzip',
        ];
        $previous = [];
        foreach ($environment as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            $this->assertSame($prefix . APP_STORAGE_BUILDS . '/app-project/deployment/code.tar.gz', Deployments::buildPath('project', 'deployment'));
            $this->assertSame($prefix . APP_STORAGE_BUILDS . '/app-project/cache/cache-key.sqfs', Deployments::cachePath('project', 'cache-key'));
            if ($device === 's3') {
                $this->assertSame('s3://compatibility' . APP_STORAGE_BUILDS . '/app-project/deployment/code.tar.gz', Storage::output('project', 'deployment'));
            }
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public static function devices(): iterable
    {
        yield 'local' => ['local', '', ''];
        yield 'virtual-host S3' => ['s3', '', ''];
        yield 'path-style S3' => ['s3', 'http://storage.invalid', 'compatibility'];
    }
}
