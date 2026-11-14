<?php

declare(strict_types=1);

namespace Tests\Unit\Webhooks;

require_once __DIR__ . '/../../../app/init/constants.php';

if (!\class_exists('Swoole\Http\Response')) {
    eval('namespace Swoole\Http; class Response {}');
}

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Delete;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Request;
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

    public function testActionThrowsWhenPreconditionDoesNotMatch(): void
    {
        $action = new Delete();
        $db = $this->createMock(Database::class);
        $request = $this->createStub(Request::class);
        $response = $this->createStub(Response::class);
        $event = $this->createStub(Event::class);
        $authorization = new Authorization();

        $storedWebhook = new Document([
            '$id' => 'wh_1',
            '$updatedAt' => '2026-10-12T06:36:34.123+00:00',
            'projectInternalId' => '1',
        ]);

        $db->expects($this->once())
            ->method('findOne')
            ->willReturn($storedWebhook);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(412);

        $project = new Document(['$id' => 'proj_1', '$sequence' => '1']);
        $action->action('wh_1', '2020-01-01T00:00:00.000+00:00', $request, $project, $response, $db, $event, $authorization);
    }

    public function testActionThrowsWhenHeaderPreconditionDoesNotMatch(): void
    {
        $action = new Delete();
        $db = $this->createMock(Database::class);
        $request = $this->createMock(Request::class);
        $response = $this->createStub(Response::class);
        $event = $this->createStub(Event::class);
        $authorization = new Authorization();

        $request->expects($this->once())
            ->method('getHeaderLine')
            ->with('x-appwrite-if-updated-at', '')
            ->willReturn('2020-01-01T00:00:00.000+00:00');

        $storedWebhook = new Document([
            '$id' => 'wh_2',
            '$updatedAt' => '2026-10-12T06:36:34.123+00:00',
            'projectInternalId' => '1',
        ]);

        $db->expects($this->once())
            ->method('findOne')
            ->willReturn($storedWebhook);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(412);

        $project = new Document(['$id' => 'proj_1', '$sequence' => '1']);
        $action->action('wh_2', null, $request, $project, $response, $db, $event, $authorization);
    }

    public function testActionDeletesWhenPreconditionMatches(): void
    {
        $action = new Delete();
        $db = $this->createMock(Database::class);
        $request = $this->createStub(Request::class);
        $response = $this->createMock(Response::class);
        $event = $this->createMock(Event::class);
        $authorization = new Authorization();

        $storedWebhook = new Document([
            '$id' => 'wh_3',
            '$updatedAt' => '2026-10-12T06:36:34.123+00:00',
            'projectInternalId' => '1',
        ]);

        $db->expects($this->once())
            ->method('findOne')
            ->willReturn($storedWebhook);

        $db->expects($this->once())
            ->method('deleteDocument')
            ->with('webhooks', 'wh_3')
            ->willReturn(true);

        $db->expects($this->once())
            ->method('purgeCachedDocument')
            ->with('projects', 'proj_1');

        $event->expects($this->once())
            ->method('setParam')
            ->with('webhookId', 'wh_3')
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('noContent');

        $project = new Document(['$id' => 'proj_1', '$sequence' => '1']);
        $action->action('wh_3', '2026-10-12T06:36:34.123+00:00', $request, $project, $response, $db, $event, $authorization);
    }

    public function testActionDeletesUnconditionallyWhenPreconditionAbsent(): void
    {
        $action = new Delete();
        $db = $this->createMock(Database::class);
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $event = $this->createMock(Event::class);
        $authorization = new Authorization();

        $request->expects($this->once())
            ->method('getHeaderLine')
            ->with('x-appwrite-if-updated-at', '')
            ->willReturn('');

        $storedWebhook = new Document([
            '$id' => 'wh_4',
            '$updatedAt' => '2026-10-12T06:36:34.123+00:00',
            'projectInternalId' => '1',
        ]);

        $db->expects($this->once())
            ->method('findOne')
            ->willReturn($storedWebhook);

        $db->expects($this->once())
            ->method('deleteDocument')
            ->with('webhooks', 'wh_4')
            ->willReturn(true);

        $db->expects($this->once())
            ->method('purgeCachedDocument')
            ->with('projects', 'proj_1');

        $event->expects($this->once())
            ->method('setParam')
            ->with('webhookId', 'wh_4')
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('noContent');

        $project = new Document(['$id' => 'proj_1', '$sequence' => '1']);
        $action->action('wh_4', null, $request, $project, $response, $db, $event, $authorization);
    }
}
