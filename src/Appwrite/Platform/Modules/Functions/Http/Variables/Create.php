<?php

namespace Appwrite\Platform\Modules\Functions\Http\Variables;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions/:functionId/variables')
            ->desc('Create variable')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('audits.event', 'variable.create')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                name: 'createVariable',
                description: <<<EOT
                Create a new function environment variable. These variables can be accessed in the function at runtime as environment variables.
                EOT,
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_VARIABLE,
                    )
                ]
            ))
            ->param('functionId', '', new UID(), 'Function unique ID.', false)
            ->param('key', null, new Text(Database::LENGTH_KEY), 'Variable key. Max length: ' . Database::LENGTH_KEY  . ' chars.', false)
            ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', false)
            ->param('secret', true, new Boolean(), 'Secret variables can be updated or deleted, but only functions can read them during build and runtime.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->callback([$this, 'action']);
    }

    public function action(string $functionId, string $key, string $value, bool $secret, Response $response, Database $dbForProject, Database $dbForPlatform)
    {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $variableId = ID::unique();

        $variable = new Document([
            '$id' => $variableId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceInternalId' => $function->getInternalId(),
            'resourceId' => $function->getId(),
            'resourceType' => 'function',
            'key' => $key,
            'value' => $value,
            'secret' => $secret,
            'search' => implode(' ', [$variableId, $function->getId(), $key, 'function']),
        ]);

        try {
            $variable = $dbForProject->createDocument('variables', $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForPlatform->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deploymentId')));
        Authorization::skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
