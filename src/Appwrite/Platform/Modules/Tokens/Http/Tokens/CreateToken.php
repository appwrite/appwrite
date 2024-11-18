<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens;

use Appwrite\Auth\Auth;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;
use Utopia\Validator\WhiteList;

class CreateToken extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createToken';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
        ->setHttpPath('/v1/tokens')
        ->desc('Create token')
        ->groups(['api', 'token'])
        ->label('scope', 'tokens.write')
        ->label('audits.event', 'token.create')
        ->label('event', 'tokens.[tokenId].create')
        ->label('audits.resource', 'token/{response.$id}')
        ->label('usage.metric', 'tokens.{scope}.requests.create')
        ->label('usage.params', ['resourceId:{request.resourceId}', 'resourceType:{request.resourceType}'])
        ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
        ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
        ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
        ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
        ->label('sdk.namespace', 'tokens')
        ->label('sdk.method', 'create')
        ->label('sdk.description', '/docs/references/tokens/create.md')
        ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
        ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
        ->label('sdk.response.model', Response::MODEL_RESOURCE_TOKEN)
        ->param('resourceType', '', new WhiteList(['files']), 'Resource type one of [files].')
        ->param('resourceId', '', new UID(), 'Unique resource ID.')
        ->param('expire', null, new Nullable(new DatetimeValidator()), 'Token expiry date', true)
        ->param('permissions', [], new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permission strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
        ->inject('response')
        ->inject('dbForProject')
        ->inject('user')
        ->inject('queueForEvents')
        ->callback(fn ($resourceType, $resourceId, $expire, $permissions, $response, $dbForProject, $user, $queueForEvents) => $this->action($resourceType, $resourceId, $expire, $permissions, $response, $dbForProject, $user, $queueForEvents));
    }

    public function action(string $resourceType, string $resourceId, ?string $expire, ?array $permissions, Response $response, Database $dbForProject, Document $user, Event $queueForEvents)
    {

        if ($resourceType === 'files') {
            $ids = explode(':', $resourceId);
            if (count($ids) !== 2) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid resource id');
            }
            $bucketId = $ids[0];
            $fileId = $ids[1];
            $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

            $isAPIKey = Auth::isAppUser(Authorization::getRoles());
            $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

            if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            $fileSecurity = $bucket->getAttribute('fileSecurity', false);
            $validator = new Authorization(Database::PERMISSION_READ);
            $valid = $validator->isValid($bucket->getRead());
            if (!$fileSecurity && !$valid) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }

            if ($fileSecurity && !$valid) {
                $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
            } else {
                $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
            }

            if ($file->isEmpty()) {
                throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
            }

            $token = $dbForProject->createDocument('resourceTokens', new Document([
                '$id' => ID::unique(),
                'secret' => Auth::tokenGenerator(128),
                'resourceId' => $bucketId . ':' . $fileId,
                'resourceInternalId' => $bucket->getInternalId() . ':' . $file->getInternalId(),
                'resourceType' => 'file',
                'expire' => $expire,
                '$permissions' => $permissions
            ]));

            $queueForEvents
                ->setParam('bucketId', $bucket->getId())
                ->setParam('fileId', $file->getId())
                ->setParam('tokenId', $token->getId())
                ->setContext('bucket', $bucket)
            ;

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->dynamic($token, Response::MODEL_RESOURCE_TOKEN);
        } else {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid resource type');
        }
    }
}
