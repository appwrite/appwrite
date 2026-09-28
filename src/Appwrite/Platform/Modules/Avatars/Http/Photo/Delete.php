<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Photo;

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
use Utopia\Lock\Exception\Contention as LockContention;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

class Delete extends Action
{
    use HTTP;

    private const LOCK_TTL = 600;

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
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('usage.resource', 'user/{response.userId}')
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
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Event $queueForEvents,
        Device $deviceForFiles,
        callable $locks,
    ): void {
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $userId = $user->getId();

        try {
            $locks('avatars:photo:'.$project->getId().':'.$userId, self::LOCK_TTL, function () use ($dbForProject, $deviceForFiles, $queueForEvents, $response, $userId): void {
                $target = $dbForProject->getDocument('users', $userId);
                $livePath = $target->getAttribute('avatarPath', '');

                if ($livePath === '') {
                    $queueForEvents->reset();

                    $response->noContent();

                    return;
                }

                $live = new Document;

                $avatars = $dbForProject->find('avatars', [
                    Query::equal('userInternalId', [(string) $target->getSequence()]),
                    Query::limit(100),
                ]);

                foreach ($avatars as $candidate) {
                    if ($candidate->getAttribute('path', '') === $livePath) {
                        $live = $candidate;
                        break;
                    }
                }

                $dbForProject->updateDocument('users', $userId, new Document([
                    'avatarPath' => '',
                ]));

                if (! $live->isEmpty()) {
                    $dbForProject->deleteDocument('avatars', $live->getId());
                }

                $queueForEvents->setParam('userId', $userId);

                if (! $deviceForFiles->delete($livePath)) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove photo from storage');
                }

                $response->noContent();
            }, timeout: 120.0);
        } catch (LockContention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Photo upload is busy. Try again.');
        }
    }
}
