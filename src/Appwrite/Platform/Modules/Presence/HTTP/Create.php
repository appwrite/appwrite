<?php

namespace Appwrite\Platform\Modules\Presence\HTTP;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createPresence';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/iterative/presence')
            ->groups(['api', 'presence'])
            ->label('scope', 'documents.write')
            ->param('status', '', new Text(255), 'Presence status.')
            ->param('permissions', [], new ArrayList(new Text(255), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Permissions list.')
            ->param('expiry', null, new Text(64, 0), 'Presence expiry in ISO 8601 format.', true)
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $status,
        array $permissions,
        ?string $expiry,
        Response $response,
        Document $user,
        Database $dbForProject
    ): void {
        if (empty($user->getId())) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User is unauthorized.');
        }

        sort($permissions);

        $parsedExpiry = null;
        if (!empty($expiry)) {
            try {
                $parsedExpiry = new DateTime($expiry);
            } catch (\Throwable) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Expiry is not a valid datetime.');
            }
        }
        $presenceLog = $dbForProject->upsertDocument('presenceLogs', new Document([
            // Stable identity so repeated createPresence calls overwrite the latest state
            // instead of creating multiple documents per user.
            'userInternalId' => $user->getSequence(),
            'userId' => $user->getId(),
            '$permissions' => $permissions,
            'perms_md5' => md5((string) json_encode($permissions)),
            'status' => $status,
            'expiry' => $parsedExpiry,
            'source' => 'realtime'
        ]));

        $dbForProject->upsertDocument('presence', new Document([
            // Stable identity so the "presence" table doesn't accumulate duplicates
            '$id' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'userId' => $user->getId(),
        ]));


        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document([
                '$id' => $presenceLog->getId(),
                'userId' => $presenceLog->getAttribute('userId'),
                'status' => $presenceLog->getAttribute('status'),
                'expiry' => $presenceLog->getAttribute('expiry'),
                '$createdAt' => $presenceLog->getCreatedAt(),
                '$updatedAt' => $presenceLog->getUpdatedAt(),
            ]), Response::MODEL_PRESENCE);
    }
}
