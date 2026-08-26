<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Subtitles;

use Appwrite\Event\Event;
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
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

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
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $subtitleId,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Document $project,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $subtitle = $authorization->skip(fn () => $dbForProject->getDocument('videos_subtitles', $subtitleId));

        if ($subtitle->isEmpty() || $subtitle->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        $this->deleteSubtitle(
            $dbForProject,
            $authorization,
            $publisherForDeletes,
            $project,
            $subtitle
        );

        $queueForEvents
            ->setParam('videoId', $video->getId())
            ->setParam('subtitleId', $subtitle->getId())
            ->setPayload($response->output($subtitle, Response::MODEL_VIDEO_SUBTITLE));

        $response->noContent();
    }
}
