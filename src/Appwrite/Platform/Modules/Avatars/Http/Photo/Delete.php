<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Photo;

use Appwrite\AvatarPhotos\Providers\Custom;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Pools\Group;
use Utopia\Storage\Device;

class Delete extends Action
{
    use HTTP;

    private const LOCK_TTL = 120;

    public static function getName(): string
    {
        return 'deletePhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/avatars/photo')
            ->desc('Delete photo')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.write')
            ->label('event', 'users.[userId].update.avatar')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('audits.userId', '{user.$id}')
            ->label('usage.resource', 'user/{user.$id}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'deletePhoto',
                description: <<<'EOT'
                Delete the custom profile photo of the currently authenticated user. Photo resolution falls back to the usual sources: OAuth2 identity photos, Gravatar, Libravatar, initials, and the static placeholder.
                EOT,
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE
            ))
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->inject('pools')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Event $queueForEvents,
        Device $deviceForFiles,
        Group $pools,
    ): void {
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $userId = $user->getId();
        $lockKey = 'photos:' . $project->getId() . ':' . $userId;

        // Storage goes before each record, so a failure at any step is retried by calling again
        $delete = function () use ($dbForProject, $deviceForFiles, $user, $userId): bool {
            $photos = $dbForProject->find('photos', [
                Query::equal('userInternalId', [(string) $user->getSequence()]),
                Query::limit(APP_LIMIT_COUNT),
            ]);

            $custom = new Custom($deviceForFiles);

            try {
                foreach ($photos as $photo) {
                    if (!$custom->delete($photo)) {
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove photo from storage');
                    }

                    $dbForProject->deleteDocument('photos', $photo->getId());
                }
            } finally {
                $dbForProject->purgeCachedDocument('users', $userId);
            }

            return !empty($photos);
        };

        try {
            $deleted = $pools->get('lock')->use(fn (\Redis $redis) => (new Distributed($redis, $lockKey, self::LOCK_TTL))->withLock($delete, timeout: 30.0));
        } catch (Contention) {
            throw new Exception(Exception::GENERAL_RESOURCE_LOCKED);
        }

        if ($deleted) {
            $queueForEvents->setParam('userId', $userId);
        } else {
            $queueForEvents->reset();
        }

        $response->noContent();
    }
}
