<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Usage\Context;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase
{
    public function testMetricKeepsMetadataFromEmissionAndFinalStatus(): void
    {
        $context = (new Context())
            ->setPath('/v1/storage/buckets/bucket/files')
            ->setMethod('POST')
            ->setService('storage')
            ->setResourceType('bucket')
            ->setResourceId('bucket')
            ->setCountry('US')
            ->setHostname('https://Example.com:443/path')
            ->setSdk('web')
            ->setSdkVersion('14.0.0');

        $context->addMetric('network.requests', 1);
        $context->setStatus(201)->setResourcePath('buckets/bucket');

        $metric = $context->getMetrics()[0];
        self::assertSame('/v1/storage/buckets/bucket/files', $metric['path']);
        self::assertSame('POST', $metric['method']);
        self::assertSame(201, $metric['status']);
        self::assertSame('storage', $metric['service']);
        self::assertSame('bucket', $metric['resourceType']);
        self::assertSame('bucket', $metric['resourceId']);
        self::assertSame('buckets/bucket', $metric['resourcePath']);
        self::assertSame('us', $metric['country']);
        self::assertSame('example.com', $metric['hostname']);
    }

    public function testResetClearsMetricsAndMetadata(): void
    {
        $context = (new Context())
            ->setService('storage')
            ->setResourceId('bucket')
            ->setIp('192.0.2.1')
            ->addMetric('network.requests', 1)
            ->reset();

        self::assertTrue($context->isEmpty());
        self::assertSame('', $context->getService());
        self::assertSame('', $context->getResourceId());
        self::assertSame('', $context->getIp());
    }

    public function testFillMissingResourceDoesNotOverwriteSpecificResource(): void
    {
        $context = (new Context())
            ->setResourceType('bucket')
            ->setResourceId('bucket')
            ->addMetric('files.storage', 10)
            ->setResourceType('')
            ->setResourceId('')
            ->addMetric('network.requests', 1)
            ->fillMissingResource('project', 'project', '42');

        [$resource, $project] = $context->getMetrics();
        self::assertSame('bucket', $resource['resourceType']);
        self::assertSame('bucket', $resource['resourceId']);
        self::assertSame('project', $project['resourceType']);
        self::assertSame('project', $project['resourceId']);
        self::assertSame('42', $project['resourceInternalId']);
    }
}
