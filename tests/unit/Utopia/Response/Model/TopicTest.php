<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\BaseList;
use Appwrite\Utopia\Response\Model\Topic;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Database\Document;

final class TopicTest extends TestCase
{
    private Response $response;

    protected function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        $this->response->setModel(new Topic());
        $this->response->setModel(new BaseList('Topic list', Response::MODEL_TOPIC_LIST, 'topics', Response::MODEL_TOPIC));
    }

    public function testTargetsAreNotPartOfThePayload(): void
    {
        $output = $this->response->output($this->topic([
            'targets' => [new Document(['$id' => 'target-id', 'identifier' => 'user@example.com'])],
        ]), Response::MODEL_TOPIC);

        $this->assertArrayNotHasKey('targets', $output);
    }

    public function testPayloadIsIdenticalWithAndWithoutTargets(): void
    {
        $withTargets = $this->response->output($this->topic([
            'targets' => [new Document(['$id' => 'target-id'])],
        ]), Response::MODEL_TOPIC);

        $withoutTargets = $this->response->output($this->topic(), Response::MODEL_TOPIC);

        $this->assertSame($withoutTargets, $withTargets);
    }

    public function testListPayloadIsIdenticalWithAndWithoutTargets(): void
    {
        $withTargets = $this->response->output(new Document([
            'topics' => [$this->topic(['targets' => [new Document(['$id' => 'target-id'])]])],
            'total' => 1,
        ]), Response::MODEL_TOPIC_LIST);

        $withoutTargets = $this->response->output(new Document([
            'topics' => [$this->topic()],
            'total' => 1,
        ]), Response::MODEL_TOPIC_LIST);

        $this->assertSame($withoutTargets, $withTargets);
        $this->assertArrayNotHasKey('targets', $withTargets['topics'][0]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function topic(array $extra = []): Document
    {
        return new Document(\array_merge([
            '$id' => 'topic-id',
            '$createdAt' => '2026-01-01T00:00:00.000+00:00',
            '$updatedAt' => '2026-01-01T00:00:00.000+00:00',
            'name' => 'events',
            'emailTotal' => 7000,
            'smsTotal' => 0,
            'pushTotal' => 0,
            'subscribe' => ['users'],
        ], $extra));
    }
}
