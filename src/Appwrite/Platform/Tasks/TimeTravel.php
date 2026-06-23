<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class TimeTravel extends Action
{
    public static function getName(): string
    {
        return 'time-travel';
    }

    public function __construct()
    {
        $this
            ->desc('Create a time-travel to change $createdAt')
            ->param('projectId', '', new UID(), 'Project ID.')
            ->param('resourceType', '', new WhiteList(['deployment']), 'Type of resource.')
            ->param('resourceId', '', new UID(), 'ID of resource.')
            ->param('createdAt', '', new DatetimeValidator(), 'New value for $createdAt')
            ->inject('getProjectDB')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $resourceType, string $resourceId, string $createdAt, callable $getProjectDB, Database $dbForPlatform): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'development') === 'development';

        if (!$isDevelopment) {
            Console::error('This task is only available in development mode.');
            return;
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            Console::error('Project not found.');
            return;
        }

        $collection = match ($resourceType) {
            'deployment' => 'deployments',
            default => throw new \Exception('Resource type not implemented')
        };

        /** @var Database $dbForProject */
        $dbForProject = $getProjectDB($project);

        $resource = $dbForProject->getDocument($collection, $resourceId);
        if ($resource->isEmpty()) {
            Console::error('Resource not found.');
            return;
        }

        $update = new Document([
            '$createdAt' => $createdAt,
        ]);

        $dbForProject->withPreserveDates(fn () => $dbForProject->updateDocument($collection, $resourceId, $update));

        Console::success('Time-travel successful. Updated $createdAt for ' . $resourceType . ' ' . $resourceId . ' to ' . $createdAt);
    }
}
