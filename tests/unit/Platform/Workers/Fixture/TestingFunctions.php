<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers\Fixture;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Workers\Functions;
use Executor\Executor;
use Override;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Logger\Log;

final class TestingFunctions extends Functions
{
    /**
     * @var array<string, int>
     */
    public array $deliveries = [];

    /**
     * @var array<string, true>
     */
    public array $failures = [];

    /**
     * @var array<string, list<string|null>>
     */
    public array $executionIds = [];

    /**
     * @var array<string, list<string|null>>
     */
    public array $envelopes = [];

    /**
     * @return array{
     *     headers: array<string, string>,
     *     variables: array<string, string>
     * }
     */
    public function envelopeContext(?string $envelopeId): array
    {
        return $this->getEnvelopeContext($envelopeId);
    }

    public function childEnvelope(
        string $envelopeId,
        string $functionId,
        string $executionId,
        string $status,
    ): string {
        return $this->getChildEnvelope($envelopeId, $functionId, $executionId, $status);
    }

    #[Override]
    protected function execute(
        Log $log,
        Database $dbForProject,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Bus $bus,
        Document $project,
        Document $function,
        Executor $executor,
        string $trigger,
        string $path,
        string $method,
        array $headers,
        array $platform,
        ?string $data = null,
        ?Document $user = null,
        ?string $jwt = null,
        ?string $event = null,
        ?string $eventData = null,
        ?string $executionId = null,
        ?string $envelopeId = null,
    ): void {
        $functionId = $function->getId();
        $this->deliveries[$functionId] = ($this->deliveries[$functionId] ?? 0) + 1;
        $this->executionIds[$functionId][] = $executionId;
        $this->envelopes[$functionId][] = $envelopeId;

        if (isset($this->failures[$functionId])) {
            throw new \Exception('function delivery interrupted');
        }
    }
}
