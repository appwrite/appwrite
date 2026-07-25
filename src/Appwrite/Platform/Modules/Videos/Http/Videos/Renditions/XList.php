<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Renditions;

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
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;
use Utopia\Validator\WhiteList;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listRenditions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/renditions')
            ->desc('List renditions')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'renditions',
                name: 'listRenditions',
                description: '/docs/references/videos/list-renditions.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_RENDITION_LIST,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('output', null, new Nullable(new WhiteList(self::OUTPUTS, true)), 'Only return renditions packaged for this output format.', true, enum: new Enum(name: 'VideoOutput'))
            ->param('status', null, new Nullable(new WhiteList([self::STATUS_WAITING, self::STATUS_STARTED, self::STATUS_ENDED, self::STATUS_UPLOADING, self::STATUS_READY, self::STATUS_ERROR], true)), 'Only return renditions in this transcoding state.', true, enum: new Enum(name: 'VideoRenditionStatus'))
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        ?string $output,
        ?string $status,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $queries = [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ];

        // The pre-merge endpoint hard-filtered on status=ready, which hid failed and
        // in-progress renditions from the only endpoint that could report on them.
        if (!empty($output)) {
            $queries[] = Query::equal('output', [$output]);
        }

        if (!empty($status)) {
            $queries[] = Query::equal('status', [$status]);
        }

        $renditions = $authorization->skip(fn () => $dbForProject->find('videos_renditions', $queries));

        $response->dynamic(new Document([
            'renditions' => $renditions,
            'total' => \count($renditions),
        ]), Response::MODEL_VIDEO_RENDITION_LIST);
    }
}
