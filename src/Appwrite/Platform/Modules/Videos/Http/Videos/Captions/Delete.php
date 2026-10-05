<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Captions;

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
        return 'deleteCaption';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/videos/:videoId/captions/:captionId')
            ->desc('Delete caption')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].captions.[captionId].delete')
            ->label('audits.event', 'caption.delete')
            ->label('audits.resource', 'video/{request.videoId}/caption/{request.captionId}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'captions',
                name: 'deleteCaption',
                description: '/docs/references/videos/delete-caption.md',
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
            ->param('captionId', '', new UID(), 'Caption unique ID.')
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
        string $captionId,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Document $project,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $caption = $authorization->skip(fn () => $dbForProject->getDocument('videos_captions', $captionId));

        if ($caption->isEmpty() || $caption->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_CAPTION_NOT_FOUND);
        }

        $this->deleteCaption(
            $dbForProject,
            $authorization,
            $publisherForDeletes,
            $project,
            $caption
        );

        $queueForEvents
            ->setParam('videoId', $video->getId())
            ->setParam('captionId', $caption->getId())
            ->setPayload($response->output($caption, Response::MODEL_VIDEO_CAPTION));

        $response->noContent();
    }
}
