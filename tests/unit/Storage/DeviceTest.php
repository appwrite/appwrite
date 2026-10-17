<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceTest extends TestCase
{
    /**
     * A path-style device refuses a bucket-level request outside its bucket before sending anything,
     * because it addresses the bucket as /{bucket}/. A virtual-hosted device has no such guard and
     * goes to the endpoint, which is unresolvable here.
     */
    #[DataProvider('endpoints')]
    public function testAddressingFollowsTheEndpoint(string $endpoint, bool $pathStyle): void
    {
        $environment = [
            '_APP_STORAGE_DEVICE' => 's3',
            '_APP_STORAGE_S3_ENDPOINT' => $endpoint,
            '_APP_STORAGE_S3_ACCESS_KEY' => 'compatibility-access',
            '_APP_STORAGE_S3_SECRET' => 'compatibility-secret',
            '_APP_STORAGE_S3_BUCKET' => 'compatibility',
            '_APP_STORAGE_S3_REGION' => 'us-east-1',
            '_APP_CONNECTIONS_STORAGE' => '',
        ];
        $previous = [];
        foreach ($environment as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            $device = getDevice('/storage/uploads/app-project');
            $this->assertSame('compatibility/storage/uploads/app-project', $device->getRoot());

            $error = null;
            try {
                $device->delete('outside/file');
            } catch (\Throwable $error) {
            }

            $this->assertInstanceOf(\Throwable::class, $error);
            $refused = $error instanceof \InvalidArgumentException
                && $error->getMessage() === 'Path is outside bucket compatibility: outside/file';
            $this->assertSame($pathStyle, $refused, $error::class . ': ' . $error->getMessage());
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function endpoints(): iterable
    {
        yield 'service host' => ['http://storage.invalid', true];
        yield 'service host without a scheme' => ['storage.invalid', true];
        yield 'service host with a port' => ['http://storage.invalid:9000/', true];
        yield 'bucket subdomain' => ['http://compatibility.storage.invalid', false];
        yield 'uppercase bucket subdomain' => ['http://COMPATIBILITY.Storage.Invalid', false];
        yield 'host is the bucket' => ['http://compatibility', false];
        yield 'bucket in the endpoint path' => ['http://storage.invalid/compatibility', false];
    }
}
