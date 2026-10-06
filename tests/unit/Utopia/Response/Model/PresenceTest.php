<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Presence;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Database\Document;

final class PresenceTest extends TestCase
{
    private Response $response;

    protected function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        $this->response->setModel(new Presence());
    }

    public function testEmptyMetadataIsObject(): void
    {
        $presence = $this->response->output(new Document([
            '$permissions' => [],
            'metadata' => [],
        ]), Response::MODEL_PRESENCE);

        $this->assertInstanceOf(\stdClass::class, $presence['metadata']);
        $this->assertSame('{}', \json_encode($presence['metadata']));
    }

    public function testMetadataIsPreserved(): void
    {
        $presence = $this->response->output(new Document([
            '$permissions' => [],
            'metadata' => ['key' => 'value'],
        ]), Response::MODEL_PRESENCE);

        $this->assertSame(['key' => 'value'], $presence['metadata']);
    }

    public function testMissingMetadataIsNull(): void
    {
        $presence = $this->response->output(new Document([
            '$permissions' => [],
        ]), Response::MODEL_PRESENCE);

        $this->assertNull($presence['metadata']);
    }
}
