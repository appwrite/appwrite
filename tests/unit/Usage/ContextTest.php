<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Usage\Context;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function testMetricCarriesRequestMetadata(): void
    {
        $context = new Context();
        $context
            ->setProtocol('https')
            ->setAccept('application/json')
            ->setAcceptLanguage('en-US')
            ->setQueryKeys('limit,offset')
            ->addMetric('files.storage', 42);

        $metrics = $context->getMetrics();

        $this->assertCount(1, $metrics);
        $metric = $metrics[0];
        $this->assertSame('https', $metric['protocol']);
        $this->assertSame('application/json', $metric['accept']);
        $this->assertSame('en-US', $metric['acceptLanguage']);
        $this->assertSame('limit,offset', $metric['queryKeys']);
    }

    public function testQueryKeysHoldsOnlyNames(): void
    {
        // queryKeys is documented to exclude values; the caller joins names only.
        $context = new Context();
        $context->setQueryKeys('limit,offset,cursor')->addMetric('files.storage', 1);

        $queryKeys = $context->getMetrics()[0]['queryKeys'];

        $this->assertSame('limit,offset,cursor', $queryKeys);
        $this->assertStringNotContainsString('=', $queryKeys);
    }

    public function testResetClearsRequestMetadata(): void
    {
        $context = new Context();
        $context
            ->setProtocol('https')
            ->setAccept('application/json')
            ->setAcceptLanguage('en-US')
            ->setQueryKeys('limit,offset')
            ->addMetric('files.storage', 42);

        $context->reset();
        $context->addMetric('files.storage', 7);

        $metric = $context->getMetrics()[0];
        $this->assertSame('', $metric['protocol']);
        $this->assertSame('', $metric['accept']);
        $this->assertSame('', $metric['acceptLanguage']);
        $this->assertSame('', $metric['queryKeys']);
    }
}
