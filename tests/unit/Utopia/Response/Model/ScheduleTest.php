<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Schedule;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Database\Document;

final class ScheduleTest extends TestCase
{
    private Response $response;

    protected function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        $this->response->setModel(new Schedule());
    }

    public function testEmptyDataIsObject(): void
    {
        $schedule = $this->response->output(new Document([
            'data' => [],
        ]), Response::MODEL_SCHEDULE);

        $this->assertSame('{}', \json_encode($schedule['data']));
    }

    public function testMissingDataDefaultsToObject(): void
    {
        $schedule = $this->response->output(new Document([]), Response::MODEL_SCHEDULE);

        $this->assertSame('{}', \json_encode($schedule['data']));
    }

    public function testDataIsPreserved(): void
    {
        $schedule = $this->response->output(new Document([
            'data' => ['key' => 'value'],
        ]), Response::MODEL_SCHEDULE);

        $this->assertSame(['key' => 'value'], $schedule['data']);
    }
}
