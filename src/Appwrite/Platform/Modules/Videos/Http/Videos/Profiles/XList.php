<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Profiles;

use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listProfiles';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/profiles')
            ->desc('List video profiles')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'profiles',
                name: 'listProfiles',
                description: '/docs/references/videos/list-profiles.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_PROFILE_LIST,
                    )
                ]
            ))
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('codec', self::CODEC_H264, new WhiteList(self::codecIds(), true), 'Only return profiles for this encode codec. Defaults to `h264`.', true, enum: new Enum(name: 'VideoCodec'))
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $search,
        string $codec,
        Response $response,
        Database $dbForProject,
        Authorization $authorization
    ): void {
        $codec = self::normalizeCodec($codec);
        $this->assertCodecEnabled($codec);

        $queries = [
            Query::equal('codec', [$codec]),
            Query::orderAsc('height'),
            Query::limit(APP_LIMIT_SUBQUERY),
        ];

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        $profiles = $authorization->skip(fn () => $dbForProject->find('videos_profiles', $queries));

        $response->dynamic(new Document([
            'profiles' => $profiles,
            'total' => \count($profiles),
        ]), Response::MODEL_VIDEO_PROFILE_LIST);
    }
}
