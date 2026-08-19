<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Usage\Context;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
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
        $this->assertSame('/v1/storage/buckets/bucket/files', $metric['path']);
        $this->assertSame('POST', $metric['method']);
        $this->assertSame(201, $metric['status']);
        $this->assertSame('storage', $metric['service']);
        $this->assertSame('bucket', $metric['resourceType']);
        $this->assertSame('bucket', $metric['resourceId']);
        $this->assertSame('buckets/bucket', $metric['resourcePath']);
        $this->assertSame('us', $metric['country']);
        $this->assertSame('example.com', $metric['hostname']);
    }

    public function testResetClearsMetricsAndMetadata(): void
    {
        $context = (new Context())
            ->setService('storage')
            ->setResourceId('bucket')
            ->setIp('192.0.2.1')
            ->addMetric('network.requests', 1)
            ->reset();

        $this->assertTrue($context->isEmpty());
        $this->assertSame('', $context->getService());
        $this->assertSame('', $context->getResourceId());
        $this->assertSame('', $context->getIp());
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
        $this->assertSame('bucket', $resource['resourceType']);
        $this->assertSame('bucket', $resource['resourceId']);
        $this->assertSame('project', $project['resourceType']);
        $this->assertSame('project', $project['resourceId']);
        $this->assertSame('42', $project['resourceInternalId']);
    }
}
