<?php

namespace Appwrite\Messaging;

use Appwrite\Event\Realtime;
use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Console\Console;
use Utopia\Database\Document;

/**
 * A user's live MQTT and realtime connections, ended once they lose access.
 */
class Connections
{
    public function __construct(
        private readonly Mqtt $mqtt,
        private readonly Realtime $realtime,
    ) {
    }

    /**
     * Ends the user's connections that lost access. Realtime connections end on the request's
     * own user event, which they still receive first.
     */
    public function revoke(Document $project, string $userId): void
    {
        try {
            $this->mqtt->revoke($project->getId(), $userId);
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }
    }

    /**
     * Ends the user's connections that lost access before anything else the request publishes,
     * as that is meant for someone else, like the new owner of a claimed account.
     */
    public function close(Document $project, string $userId): void
    {
        $this->revoke($project, $userId);

        // Same channel as the request's own event, so it arrives first
        $this->realtime
            ->reset()
            ->setProject($project)
            ->setEvent('users.[userId].sessions.delete')
            ->setParam('userId', $userId)
            ->trigger();
    }
}
