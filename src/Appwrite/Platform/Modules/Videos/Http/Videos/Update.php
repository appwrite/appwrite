<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
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
use Utopia\Validator\Text;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateVideo';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/videos/:videoId')
            ->desc('Update video')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].update')
            ->label('audits.event', 'video.update')
            ->label('audits.resource', 'video/{request.videoId}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'videos',
                name: 'update',
                description: '/docs/references/videos/update-video.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('name', '', new Text(128), 'Video name. Max length: 128 chars.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $name,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $video = $authorization->skip(fn () => $dbForProject->updateDocument('videos', $video->getId(), new Document([
            'name' => $name,
            'search' => \implode(' ', [$video->getAttribute('fileId', ''), $name]),
        ])));

        $queueForEvents->setParam('videoId', $video->getId());

        $response->dynamic($video, Response::MODEL_VIDEO);
    }
}
