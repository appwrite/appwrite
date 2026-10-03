<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Profiles;

use Appwrite\Event\Event;
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
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateProfile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/videos/profiles/:profileId')
            ->desc('Update video profile')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videoProfiles.[profileId].update')
            ->label('audits.event', 'profile.update')
            ->label('audits.resource', 'videoProfile/{request.profileId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'profiles',
                name: 'updateProfile',
                description: '/docs/references/videos/update-profile.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_PROFILE,
                    )
                ]
            ))
            ->param('profileId', '', new UID(), 'Video profile unique ID.')
            ->param('name', '', new Text(128), 'Video profile name.')
            ->param('videoBitRate', null, new Range(self::MIN_VIDEO_BITRATE, self::MAX_VIDEO_BITRATE), 'Target video bitrate in kilobits per second.')
            ->param('audioBitRate', null, new Range(self::MIN_AUDIO_BITRATE, self::MAX_AUDIO_BITRATE), 'Target audio bitrate in kilobits per second.')
            ->param('width', null, new Range(self::MIN_DIMENSION, self::MAX_DIMENSION), 'Target video width in pixels.')
            ->param('height', null, new Range(self::MIN_DIMENSION, self::MAX_DIMENSION), 'Target video height in pixels.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
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
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $this->assertPrivilegedCaller($user, $authorization);

        $profile = $authorization->skip(fn () => $dbForProject->getDocument('videos_profiles', $profileId));

        // The pre-merge endpoint threw PROJECT_NOT_FOUND here.
        if ($profile->isEmpty()) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $profile
            ->setAttribute('name', $name)
            ->setAttribute('videoBitRate', $videoBitRate)
            ->setAttribute('audioBitRate', $audioBitRate)
            ->setAttribute('width', $width)
            ->setAttribute('height', $height)
            ->setAttribute('search', $name);

        $profile = $authorization->skip(fn () => $dbForProject->updateDocument('videos_profiles', $profile->getId(), $profile));

        $queueForEvents->setParam('profileId', $profile->getId());

        $response->dynamic($profile, Response::MODEL_VIDEO_PROFILE);
    }
}
