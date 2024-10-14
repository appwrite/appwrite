<?php

namespace Appwrite\Platform\Modules\FunctionsVariables\Http;

use Appwrite\Extend\Exception;
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
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'create';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions/:functionId/variables')
            ->desc('Create variable')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('audits.event', 'variable.create')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'functions')
            ->label('sdk.method', 'createVariable')
            ->label('sdk.description', '/docs/references/functions/create-variable.md')
            ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_VARIABLE)
            ->param('functionId', '', new UID(), 'Function unique ID.', false)
            ->param('key', null, new Text(Database::LENGTH_KEY), 'Variable key. Max length: ' . Database::LENGTH_KEY  . ' chars.', false)
            ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', false)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForConsole')
            ->callback(fn ($functionId, $key, $value, $response, $dbForProject, $dbForConsole) => $this->action($functionId, $key, $value, $response, $dbForProject, $dbForConsole));
    }

    public function action(string $functionId, string $key, string $value, Response $response, Database $dbForProject, Database $dbForConsole)
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
            'search' => implode(' ', [$variableId, $function->getId(), $key, 'function']),
        ]);

        try {
            $variable = $dbForProject->createDocument('variables', $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
