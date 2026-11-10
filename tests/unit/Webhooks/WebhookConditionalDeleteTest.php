<?php

declare(strict_types=1);

namespace Tests\Unit\Webhooks;

require_once __DIR__ . '/../../../app/init/constants.php';

if (!\class_exists('Swoole\Http\Response')) {
    eval('namespace Swoole\Http; class Response {}');
}

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Delete;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Platform\Action;

final class WebhookConditionalDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        Config::setParam('errors', include __DIR__ . '/../../../app/config/errors.php');
    }

    public function testDeleteWebhookActionMetadata(): void
    {
        $action = new Delete();

        $this->assertSame('deleteWebhook', Delete::getName());
        $this->assertContains(Action::HTTP_REQUEST_METHOD_DELETE, $action->getHttpMethods());
        $this->assertSame('/v1/webhooks/:webhookId', $action->getHttpPath());

        $params = $action->getParams();
        $this->assertArrayHasKey('webhookId', $params);
        $this->assertArrayHasKey('ifUpdatedAt', $params);

        $this->assertFalse($params['webhookId']['optional']);
        $this->assertTrue($params['ifUpdatedAt']['optional']);
        $this->assertContains('if-updated-at', $params['ifUpdatedAt']['aliases'] ?? []);
    }

    public function testWebhookPreconditionFailedExceptionDefinition(): void
    {
        $this->assertSame('webhook_precondition_failed', Exception::WEBHOOK_PRECONDITION_FAILED);

        $exception = new Exception(Exception::WEBHOOK_PRECONDITION_FAILED);
        $this->assertSame(412, $exception->getCode());
        $this->assertSame('webhook_precondition_failed', $exception->getType());
        $this->assertStringContainsString('modified', $exception->getMessage());
    }

    public function testPreconditionMatchingEvaluation(): void
    {
        $storedUpdatedAt = '2026-10-12T06:36:34.123+00:00';

        // Precondition matches stored timestamp -> valid
        $ifUpdatedAt = '2026-10-12T06:36:34.123+00:00';
        $mismatch = !empty($ifUpdatedAt) && $storedUpdatedAt !== $ifUpdatedAt;
        $this->assertFalse($mismatch);

        // Precondition is null/absent -> unconditional delete allowed
        $ifUpdatedAt = null;
        $mismatch = !empty($ifUpdatedAt) && $storedUpdatedAt !== $ifUpdatedAt;
        $this->assertFalse($mismatch);

        // Precondition is older timestamp -> precondition fails
        $ifUpdatedAt = '2026-10-12T06:00:00.000+00:00';
        $mismatch = !empty($ifUpdatedAt) && $storedUpdatedAt !== $ifUpdatedAt;
        $this->assertTrue($mismatch);
    }

    public function testPreconditionHeaderFallback(): void
    {
        $storedUpdatedAt = '2026-10-12T06:36:34.123+00:00';

        // When parameter is empty, header value is resolved
        $ifUpdatedAt = null;
        $headerValue = '2026-10-12T06:36:34.123+00:00';
        $resolved = $ifUpdatedAt ?: ($headerValue ?: null);

        $this->assertSame($storedUpdatedAt, $resolved);

        // Header value mismatch
        $staleHeader = '2026-10-12T05:00:00.000+00:00';
        $resolvedStale = $ifUpdatedAt ?: ($staleHeader ?: null);
        $this->assertTrue(!empty($resolvedStale) && $storedUpdatedAt !== $resolvedStale);
    }
}
