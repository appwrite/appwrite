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

final class TestingExecutionFunctions extends Functions
{
    #[Override]
    public function execute(
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
        parent::execute(
            log: $log,
            dbForProject: $dbForProject,
            queueForWebhooks: $queueForWebhooks,
            publisherForFunctions: $publisherForFunctions,
            queueForRealtime: $queueForRealtime,
            queueForEvents: $queueForEvents,
            bus: $bus,
            project: $project,
            function: $function,
            executor: $executor,
            trigger: $trigger,
            path: $path,
            method: $method,
            headers: $headers,
            platform: $platform,
            data: $data,
            user: $user,
            jwt: $jwt,
            event: $event,
            eventData: $eventData,
            executionId: $executionId,
            envelopeId: $envelopeId,
        );
    }
}
