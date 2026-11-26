<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Videos\Profiles;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createVideoProfile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/project/videos/profiles')
            ->httpAlias('/v1/projects/:projectId/videos/profiles')
            ->desc('Create video profile')
            ->groups(['api', 'project'])
            ->label('scope', 'project.videos.profiles.write')
            ->label('event', 'projects.[projectId].profiles.[profileId].create')
            ->label('audits.event', 'project.video-profile.create')
            ->label('audits.resource', 'project.video-profile/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'profiles',
                name: 'createVideoProfile',
                // Hidden until videos is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
                description: '/docs/references/project/create-video-profile.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_VIDEO_PROFILE,
                    )
                ]
            ))
            ->param('profileId', '', new CustomId(), 'Video profile ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Video profile name.')
            ->param('videoBitRate', null, new Range(self::MIN_VIDEO_BITRATE * 1000, self::MAX_VIDEO_BITRATE * 1000), 'Target video bitrate in bits per second. Must be a multiple of 1000.')
            ->param('audioBitRate', null, new Range(self::MIN_AUDIO_BITRATE * 1000, self::MAX_AUDIO_BITRATE * 1000), 'Target audio bitrate in bits per second. Must be a multiple of 1000.')
            ->param('width', null, new Range(self::MIN_DIMENSION, self::MAX_DIMENSION), 'Target video width in pixels.')
            ->param('height', null, new Range(self::MIN_DIMENSION, self::MAX_DIMENSION), 'Target video height in pixels.')
            ->param('codec', self::CODEC_H264, new WhiteList(self::codecIds(), true), 'Video encode codec. Codecs not enabled on this instance are rejected. Defaults to `h264`.', true, enum: new Enum(name: 'VideoCodecId'))
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $profileId,
        string $name,
        int $videoBitRate,
        int $audioBitRate,
        int $width,
        int $height,
        string $codec,
        Response $response,
        Database $dbForProject,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $codec = self::normalizeCodec($codec);
        $this->assertCodecEnabled($codec);
        $profileId = $profileId === 'unique()' ? Id::unique() : $profileId;

        try {
            $profile = $authorization->skip(fn () => $dbForProject->createDocument('videosProfiles', new Document([
                '$id' => $profileId,
                'name' => $name,
                'codec' => $codec,
                'videoBitRate' => self::toKilobits('videoBitRate', $videoBitRate),
                'audioBitRate' => self::toKilobits('audioBitRate', $audioBitRate),
                'width' => $width,
                'height' => $height,
                'search' => $name,
            ])));
        } catch (DuplicateException) {
            throw new Exception(Exception::VIDEO_PROFILE_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('profileId', $profile->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($profile, Response::MODEL_VIDEO_PROFILE);
    }
}
