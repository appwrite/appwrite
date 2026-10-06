<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Migration;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Database\Document;

final class MigrationTest extends TestCase
{
    private Response $response;

    protected function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        $this->response->setModel(new Migration());
    }

    public function testEmptyObjectsAreObjects(): void
    {
        $migration = $this->response->output(new Document([
            'statusCounters' => [],
            'options' => [],
        ]), Response::MODEL_MIGRATION);

        $this->assertSame('{}', \json_encode($migration['statusCounters']));
        $this->assertSame('{}', \json_encode($migration['options']));
        $this->assertSame([], $migration['resourceData']);
    }

    public function testMissingObjectsDefaultToObjects(): void
    {
        $migration = $this->response->output(new Document([]), Response::MODEL_MIGRATION);

        $this->assertSame('{}', \json_encode($migration['statusCounters']));
        $this->assertSame('{}', \json_encode($migration['options']));
    }

    public function testObjectsArePreserved(): void
    {
        $migration = $this->response->output(new Document([
            'statusCounters' => ['Database' => ['SUCCESS' => 1]],
            'options' => ['bucketId' => 'exports'],
            'errors' => ['{"code":0,"message":"Failed","trace":"stack"}'],
        ]), Response::MODEL_MIGRATION);

        $this->assertSame(['Database' => ['SUCCESS' => 1]], $migration['statusCounters']);
        $this->assertSame(['bucketId' => 'exports'], $migration['options']);
        $this->assertSame(['{"code":0,"message":"Failed"}'], $migration['errors']);
    }
}
