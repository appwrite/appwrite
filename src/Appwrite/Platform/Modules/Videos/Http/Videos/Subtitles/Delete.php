<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Subtitles;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteSubtitle';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/videos/:videoId/subtitles/:subtitleId')
            ->desc('Delete subtitle')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].subtitles.[subtitleId].delete')
            ->label('audits.event', 'subtitle.delete')
            ->label('audits.resource', 'video/{request.videoId}/subtitle/{request.subtitleId}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'subtitles',
                name: 'deleteSubtitle',
                description: '/docs/references/videos/delete-subtitle.md',
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
            ->param('subtitleId', '', new UID(), 'Subtitle unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->inject('deviceForVideos')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $subtitleId,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Device $deviceForVideos,
        Event $queueForEvents
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $subtitle = $authorization->skip(fn () => $dbForProject->getDocument('videos_subtitles', $subtitleId));

        if ($subtitle->isEmpty() || $subtitle->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        // Segments first: a failure after the parent is gone would orphan them, and
        // nothing else knows how to find them.
        $segments = $authorization->skip(fn () => $dbForProject->find('videos_subtitles_segments', [
            Query::equal('subtitleInternalId', [$subtitle->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        foreach ($segments as $segment) {
            $authorization->skip(fn () => $dbForProject->deleteDocument('videos_subtitles_segments', $segment->getId()));
        }

        $deleted = $authorization->skip(fn () => $dbForProject->deleteDocument('videos_subtitles', $subtitle->getId()));

        if (!$deleted) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove video subtitle from DB');
        }

        $path = $subtitle->getAttribute('path', '');

        if (!empty($path)) {
            try {
                $deviceForVideos->delete($path);
            } catch (\Throwable) {
                // The row is already gone; a stale artifact is cleaned up when the
                // video itself is deleted.
            }
        }

        $queueForEvents
            ->setParam('videoId', $video->getId())
            ->setParam('subtitleId', $subtitle->getId())
            ->setPayload($response->output($subtitle, Response::MODEL_VIDEO_SUBTITLE));

        $response->noContent();
    }
}
