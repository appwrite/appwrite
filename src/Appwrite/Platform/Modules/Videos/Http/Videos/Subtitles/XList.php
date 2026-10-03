<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Subtitles;

use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listSubtitles';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/subtitles')
            ->desc('List subtitles')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'subtitles',
                name: 'listSubtitles',
                description: '/docs/references/videos/list-subtitles.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_SUBTITLE_LIST,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $subtitles = $authorization->skip(fn () => $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        $response->dynamic(new Document([
            'subtitles' => $subtitles,
            'total' => \count($subtitles),
        ]), Response::MODEL_VIDEO_SUBTITLE_LIST);
    }
}
