<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\CredentialsRevoked;
use Appwrite\Event\Realtime;
use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Bus\Listener;
use Utopia\Console\Console;
use Utopia\Database\Document;
use Utopia\Span\Span;

class Connections extends Listener
{
    public static function getName(): string
    {
        return 'connections';
    }

    public static function getEvents(): array
    {
        return [CredentialsRevoked::class];
    }

    public function __construct()
    {
        $this
            ->desc('Ends the live connections of a user whose credentials were revoked')
            ->inject('mqtt')
            ->callback($this->handle(...));
    }

    public function handle(CredentialsRevoked $event, Mqtt $mqtt): void
    {
        $project = new Document($event->project);
        $userId = $event->user['$id'] ?? '';

        Span::add('project.id', $project->getId());
        Span::add('user.id', $userId);

        try {
            $mqtt->revoke($project->getId(), $userId);
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }

        // The same event as signing out everywhere: realtime closes every connection whose
        // session is gone. Published now, so it arrives before the request's own event, which
        // would otherwise reach those connections with the account's new data.
        (new Realtime())
            ->setProject($project)
            ->setEvent('users.[userId].sessions.delete')
            ->setParam('userId', $userId)
            ->trigger();
    }
}
