<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteVideo';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/videos/:videoId')
            ->desc('Delete video')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].delete')
            ->label('audits.event', 'video.delete')
            ->label('audits.resource', 'video/{request.videoId}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'videos',
                name: 'delete',
                description: '/docs/references/videos/delete-video.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes
    ): void {
        $video = $this->getAuthorizedVideo($dbForProject, $authorization, $user, $videoId);

        // Encode exits if the row is gone while still pending. After claim the
        // worker still writes through started → ended → uploading, so delete
        // must wait. Same retention as abortStaleRenditions.
        $retention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_RENDITION', '86400');
        $cutoff = DateTime::addSeconds(new \DateTime(), -1 * $retention);
        $inProgress = $authorization->skip(fn () => $dbForProject->count('videos_renditions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('status', [
                self::STATUS_STARTED,
                self::STATUS_ENDED,
                self::STATUS_UPLOADING,
            ]),
            Query::greaterThan('$updatedAt', $cutoff),
        ]));
        if ($inProgress > 0) {
            throw new Exception(Exception::VIDEO_RENDITION_IN_PROGRESS);
        }

        $roles = $authorization->getRoles();
        if ($user->isPrivileged($roles) || $user->isKey($roles)) {
            $deleted = $authorization->skip(fn () => $dbForProject->deleteDocument('videos', $video->getId()));
        } else {
            $deleted = $dbForProject->deleteDocument('videos', $video->getId());
        }

        if (!$deleted) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove video from DB');
        }

        // The pre-merge controller built this event but never triggered it, so the
        // cascade never ran and renditions/captions/segments were orphaned.
        // DELETE_TYPE_DOCUMENT is correct: the deletes worker dispatches on the
        // document's collection, which is `videos`.
        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $queueForEvents->getProject(),
            type: DELETE_TYPE_DOCUMENT,
            document: $video,
        ));

        $queueForEvents
            ->setParam('videoId', $video->getId())
            ->setPayload($response->output($video, Response::MODEL_VIDEO));

        $response->noContent();
    }
}
