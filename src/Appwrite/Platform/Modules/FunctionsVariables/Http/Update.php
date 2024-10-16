<?php

namespace Appwrite\Platform\Modules\FunctionsVariables\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'update';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/functions/:functionId/variables/:variableId')
            ->desc('Update variable')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('audits.event', 'variable.update')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'functions')
            ->label('sdk.method', 'updateVariable')
            ->label('sdk.description', '/docs/references/functions/update-variable.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_VARIABLE)
            ->param('functionId', '', new UID(), 'Function unique ID.', false)
            ->param('variableId', '', new UID(), 'Variable unique ID.', false)
            ->param('key', null, new Text(255), 'Variable key. Max length: 255 chars.', false)
            ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForConsole')
            ->callback(fn ($functionId, $variableId, $key, $value, $response, $dbForProject, $dbForConsole) => $this->action($functionId, $variableId, $key, $value, $response, $dbForProject, $dbForConsole));
    }
    public function action(string $functionId, string $variableId, string $key, ?string $value, Response $response, Database $dbForProject, Database $dbForConsole)
    {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceInternalId') !== $function->getInternalId() || $variable->getAttribute('resourceType') !== 'function') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable === false || $variable->isEmpty()) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $variable
            ->setAttribute('key', $key)
            ->setAttribute('value', $value ?? $variable->getAttribute('value'))
            ->setAttribute('search', implode(' ', [$variableId, $function->getId(), $key, 'function']));

        try {
            $dbForProject->updateDocument('variables', $variable->getId(), $variable);
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

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
