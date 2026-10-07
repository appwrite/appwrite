<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Renditions\Streams\Manifest;

use Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Manifest\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getStreamManifest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/outputs/hls/renditions/:renditionId/streams/:streamId/playlist.m3u8')
            ->desc('Get stream manifest')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'playback',
                name: 'getStreamManifest',
                description: '/docs/references/videos/get-stream-manifest.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::ANY,
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'ImpersonateUserId'],
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('renditionId', '', new UID(), 'Rendition unique ID.')
            ->param('streamId', 0, new Range(0, 10), 'Stream index within the rendition.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $renditionId,
        int $streamId,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization
    ): void {
        $this->sendStreamPlaylist(
            $videoId,
            $renditionId,
            $streamId,
            self::OUTPUT_HLS,
            $response,
            $dbForProject,
            $project,
            $user,
            $authorization
        );
    }
}
