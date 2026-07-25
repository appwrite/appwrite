<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Renditions;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getRendition';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/renditions/:renditionId')
            ->desc('Get rendition')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'renditions',
                name: 'getRendition',
                description: '/docs/references/videos/get-rendition.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_RENDITION,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('renditionId', '', new UID(), 'Rendition unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $renditionId,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $rendition = $authorization->skip(fn () => $dbForProject->getDocument('videos_renditions', $renditionId));

        if ($rendition->isEmpty() || $rendition->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $response->dynamic($rendition, Response::MODEL_VIDEO_RENDITION);
    }
}
