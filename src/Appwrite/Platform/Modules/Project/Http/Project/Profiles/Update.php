<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Profiles;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
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
        return 'updateProjectProfile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/profiles/:profileId')
            ->httpAlias('/v1/projects/:projectId/profiles/:profileId')
            ->desc('Update video profile')
            ->groups(['api', 'project'])
            ->label('scope', 'project.profiles.write')
            ->label('event', 'projects.[projectId].profiles.[profileId].update')
            ->label('audits.event', 'project.profile.update')
            ->label('audits.resource', 'project.profile/{request.profileId}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'profiles',
                name: 'updateProfile',
                description: '/docs/references/project/update-profile.md',
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
        Response $response,
        Database $dbForProject,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $profile = $authorization->skip(fn () => $dbForProject->getDocument('videos_profiles', $profileId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        try {
            $profile = $authorization->skip(fn () => $dbForProject->updateDocument('videos_profiles', $profile->getId(), new Document([
                'name' => $name,
                'videoBitRate' => $videoBitRate,
                'audioBitRate' => $audioBitRate,
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

        $response->dynamic($profile, Response::MODEL_VIDEO_PROFILE);
    }
}
