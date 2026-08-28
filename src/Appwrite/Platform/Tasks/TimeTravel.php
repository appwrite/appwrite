<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\System\System;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
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
            ->desc('Create a time-travel to change document dates and video seed fields (development only)')
            ->param('projectId', '', new UID(), 'Project ID.')
            ->param('resourceType', '', new WhiteList(['deployment', 'video', 'videos_rendition']), 'Type of resource.')
            ->param('resourceId', '', new UID(), 'ID of resource.')
            ->param('createdAt', '', new Nullable(new DatetimeValidator()), 'New value for $createdAt.', true)
            ->param('updatedAt', '', new Nullable(new DatetimeValidator()), 'New value for $updatedAt.', true)
            ->param('status', '', new Nullable(new Text(64)), 'Optional status override (videos / renditions).', true)
            ->param('chunksUploaded', null, new Nullable(new Integer(true)), 'Optional chunksUploaded (videos).', true)
            ->param('chunksTotal', null, new Nullable(new Integer(true)), 'Optional chunksTotal (videos).', true)
            ->param('progress', '', new Nullable(new Text(8)), 'Optional progress (renditions).', true)
            ->inject('getProjectDB')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        string $resourceType,
        string $resourceId,
        ?string $createdAt,
        ?string $updatedAt,
        ?string $status,
        ?int $chunksUploaded,
        ?int $chunksTotal,
        ?string $progress,
        callable $getProjectDB,
        Database $dbForPlatform
    ): void {
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
            'video' => 'videos',
            'videos_rendition' => 'videos_renditions',
            default => throw new \Exception('Resource type not implemented')
        };

        /** @var Database $dbForProject */
        $dbForProject = $getProjectDB($project);

        $resource = $dbForProject->getDocument($collection, $resourceId);
        if ($resource->isEmpty()) {
            Console::error('Resource not found.');
            return;
        }

        $data = [];
        if (!empty($createdAt)) {
            $data['$createdAt'] = $createdAt;
        }
        if (!empty($updatedAt)) {
            $data['$updatedAt'] = $updatedAt;
        }
        if ($status !== null && $status !== '') {
            $data['status'] = $status;
        }
        if ($chunksUploaded !== null) {
            $data['chunksUploaded'] = $chunksUploaded;
        }
        if ($chunksTotal !== null) {
            $data['chunksTotal'] = $chunksTotal;
        }
        if ($progress !== null && $progress !== '') {
            $data['progress'] = $progress;
        }

        if ($data === []) {
            Console::error('Nothing to update. Pass createdAt, updatedAt, and/or seed fields.');
            return;
        }

        $update = new Document($data);
        $dbForProject->withPreserveDates(fn () => $dbForProject->updateDocument($collection, $resourceId, $update));

        Console::success(
            'Time-travel successful. Updated ' . $resourceType . ' ' . $resourceId
            . ' fields: ' . \implode(', ', \array_keys($data))
        );
    }
}
