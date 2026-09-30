<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Profiles;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createProfile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/videos/profiles')
            ->desc('Create video profile')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videoProfiles.[profileId].create')
            ->label('audits.event', 'profile.create')
            ->label('audits.resource', 'videoProfile/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'profiles',
                name: 'createProfile',
                description: '/docs/references/videos/create-profile.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_VIDEO_PROFILE,
                    )
                ]
            ))
            ->param('name', '', new Text(128), 'Video profile name.')
            ->param('videoBitRate', null, new Range(self::MIN_VIDEO_BITRATE, self::MAX_VIDEO_BITRATE), 'Target video bitrate in kilobits per second.')
            ->param('audioBitRate', null, new Range(self::MIN_AUDIO_BITRATE, self::MAX_AUDIO_BITRATE), 'Target audio bitrate in kilobits per second.')
            ->param('width', null, new Range(self::MIN_DIMENSION, self::MAX_DIMENSION), 'Target video width in pixels.')
            ->param('height', null, new Range(self::MIN_DIMENSION, self::MAX_DIMENSION), 'Target video height in pixels.')
            ->param('codec', self::CODEC_H264, new WhiteList(self::enabledCodecs(), true), 'Video encode codec. Only codecs enabled on this instance are accepted. Defaults to `h264`.', true, enum: new Enum(name: 'VideoCodec'))
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $name,
        int $videoBitRate,
        int $audioBitRate,
        int $width,
        int $height,
        string $codec,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $this->assertPrivilegedCaller($user, $authorization);

        $codec = self::normalizeCodec($codec);
        $this->assertCodecEnabled($codec);

        $profile = $authorization->skip(fn () => $dbForProject->createDocument('videos_profiles', new Document([
            '$id' => ID::unique(),
            'name' => $name,
            'codec' => $codec,
            'videoBitRate' => $videoBitRate,
            'audioBitRate' => $audioBitRate,
            'width' => $width,
            'height' => $height,
            'search' => $name,
        ])));

        $queueForEvents->setParam('profileId', $profile->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($profile, Response::MODEL_VIDEO_PROFILE);
    }
}
