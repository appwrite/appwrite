<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Video as VideoMessage;
use Appwrite\Event\Message\VideoAction;
use Appwrite\Event\Publisher\Video as VideoPublisher;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\System\System;
use Utopia\Validator\Boolean;
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
            ->param('resourceType', '', new WhiteList(['deployment', 'video', 'videos_rendition', 'videos_caption']), 'Type of resource.')
            ->param('resourceId', '', new UID(), 'ID of resource.')
            ->param('createdAt', '', new Nullable(new DatetimeValidator()), 'New value for $createdAt.', true)
            ->param('updatedAt', '', new Nullable(new DatetimeValidator()), 'New value for $updatedAt.', true)
            ->param('status', '', new Nullable(new Text(64)), 'Optional status override (renditions, captions; timelineStatus for a video).', true)
            ->param('progress', '', new Nullable(new Text(8)), 'Optional progress (renditions).', true)
            ->param('enqueue', false, new Boolean(true), 'Re-enqueue the videos job for this resource after seeding (video=timeline, videos_rendition=encode, videos_caption=caption).', true)
            ->inject('getProjectDB')
            ->inject('dbForPlatform')
            ->inject('publisherForVideos')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        string $resourceType,
        string $resourceId,
        ?string $createdAt,
        ?string $updatedAt,
        ?string $status,
        ?string $progress,
        bool $enqueue,
        callable $getProjectDB,
        Database $dbForPlatform,
        VideoPublisher $publisherForVideos
    ): void {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

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
            'videos_rendition' => 'videosRenditions',
            'videos_caption' => 'videosCaptions',
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
            // A video has no status of its own; its timeline job's is the one seeded.
            $data[$resourceType === 'video' ? 'timelineStatus' : 'status'] = $status;
        }
        if ($progress !== null && $progress !== '') {
            $data['progress'] = $progress;
        }

        if ($data === [] && !$enqueue) {
            Console::error('Nothing to update. Pass createdAt, updatedAt, seed fields, and/or enqueue.');
            return;
        }

        if ($data !== []) {
            $update = new Document($data);
            $dbForProject->withPreserveDates(true, fn () => $dbForProject->updateDocument($collection, $resourceId, $update));

            Console::success(
                'Time-travel successful. Updated ' . $resourceType . ' ' . $resourceId
                . ' fields: ' . \implode(', ', \array_keys($data))
            );

            $resource = $dbForProject->getDocument($collection, $resourceId);
        }

        if (!$enqueue) {
            return;
        }

        if ($resourceType === 'deployment') {
            Console::error('enqueue is only supported for video, videos_rendition, and videos_caption.');
            return;
        }

        $message = $this->videoMessage($dbForProject, $project, $resourceType, $resource);
        if ($message === null) {
            return;
        }

        $publisherForVideos->enqueue($message);
        Console::success(
            'Enqueued ' . $message->action->value . ' for ' . $resourceType . ' ' . $resourceId
        );
    }

    private function videoMessage(
        Database $dbForProject,
        Document $project,
        string $resourceType,
        Document $resource
    ): ?VideoMessage {
        return match ($resourceType) {
            'video' => new VideoMessage(
                project: $project,
                action: VideoAction::Timeline,
                video: $resource,
            ),
            'videos_rendition' => $this->encodeMessage($dbForProject, $project, $resource),
            'videos_caption' => $this->captionMessage($dbForProject, $project, $resource),
            default => null,
        };
    }

    private function encodeMessage(
        Database $dbForProject,
        Document $project,
        Document $rendition
    ): ?VideoMessage {
        $video = $dbForProject->getDocument('videos', (string) $rendition->getAttribute('videoId', ''));
        if ($video->isEmpty()) {
            Console::error('Video not found for rendition ' . $rendition->getId());
            return null;
        }

        $profile = $dbForProject->getDocument('videosProfiles', (string) $rendition->getAttribute('profileId', ''));
        if ($profile->isEmpty()) {
            Console::error('Profile not found for rendition ' . $rendition->getId());
            return null;
        }

        return new VideoMessage(
            project: $project,
            action: VideoAction::Encode,
            video: $video,
            profile: $profile,
            rendition: $rendition,
            output: (string) $rendition->getAttribute('output', ''),
        );
    }

    private function captionMessage(
        Database $dbForProject,
        Document $project,
        Document $caption
    ): ?VideoMessage {
        $video = $dbForProject->getDocument('videos', (string) $caption->getAttribute('videoId', ''));
        if ($video->isEmpty()) {
            Console::error('Video not found for caption ' . $caption->getId());
            return null;
        }

        return new VideoMessage(
            project: $project,
            action: VideoAction::Caption,
            video: $video,
            caption: $caption,
        );
    }
}
