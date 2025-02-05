<?php

namespace Appwrite\Platform\Modules\Storage\Http\Tokens;

use Appwrite\Auth\Auth;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateToken';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
        ->setHttpPath('/v1/tokens/:tokenId')
        ->desc('Update token')
        ->groups(['api', 'tokens'])
        ->label('scope', 'tokens.write')
        ->label('event', 'tokens.[tokenId].update')
        ->label('audits.event', 'tokens.update')
        ->label('audits.resource', 'tokens/{response.$id}')
        ->label('usage.metric', 'tokens.{scope}.requests.update')
        ->label('usage.params', ['tokenId:{request.tokenId}'])
        ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
        ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
        ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
        ->label('sdk', new Method(
            namespace: 'tokens',
            name: 'update',
            description: '',
            auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_RESOURCE_TOKEN,
                )
            ],
            contentType: ContentType::JSON
        ))
        ->param('tokenId', '', new UID(), 'Token unique ID.')
        ->param('expire', null, new Nullable(new DatetimeValidator()), 'File token expiry date', true)
        ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permission string. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
        ->inject('response')
        ->inject('dbForProject')
        ->inject('user')
        ->inject('mode')
        ->inject('queueForEvents')
        ->callback(fn ($tokenId, $expire, $permission, $response, $dbForProject, $queueForEvents) => $this->action($tokenId, $expire, $permission, $response, $dbForProject, $queueForEvents));
    }

    public function action(string $tokenId, ?string $expire, ?array $permissions, Response $response, Database $dbForProject, Event $queueForEvents)
    {
        $token = $dbForProject->getDocument('resourceTokens', $tokenId);

        if ($token->isEmpty()) {
            throw new Exception(Exception::TOKEN_NOT_FOUND);
        }

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions, [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ]);

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = Authorization::getRoles();
        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles) && !\is_null($permissions)) {
            foreach (Database::PERMISSIONS as $type) {
                foreach ($permissions as $permission) {
                    $permission = Permission::parse($permission);
                    if ($permission->getPermission() != $type) {
                        continue;
                    }
                    $role = (new Role(
                        $permission->getRole(),
                        $permission->getIdentifier(),
                        $permission->getDimension()
                    ))->toString();
                    if (!Authorization::isRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                    }
                }
            }
        }

        if (\is_null($permissions)) {
            $permissions = $token->getPermissions() ?? [];
        }

        $token
            ->setAttribute('expire', $expire)
            ->setAttribute('$permissions', $permissions);

        $token = $dbForProject->updateDocument('resourceTokens', $tokenId, $token);

        $queueForEvents
            ->setParam('tokenId', $token->getId())
        ;

        $response->dynamic($token, Response::MODEL_RESOURCE_TOKEN);
    }
}
