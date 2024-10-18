<?php

namespace Appwrite\Platform\Modules\FunctionsVariables\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'delete';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/functions/:functionId/variables/:variableId')
            ->desc('Delete variable')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('audits.event', 'variable.delete')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'functions')
            ->label('sdk.method', 'deleteVariable')
            ->label('sdk.description', '/docs/references/functions/delete-variable.md')
            ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
            ->label('sdk.response.model', Response::MODEL_NONE)
            ->param('functionId', '', new UID(), 'Function unique ID.', false)
            ->param('variableId', '', new UID(), 'Variable unique ID.', false)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForConsole')
            ->callback(fn ($functionId, $variableId, $response, $dbForProject, $dbForConsole) => $this->action($functionId, $variableId, $response, $dbForProject, $dbForConsole));
    }

    public function action(string $functionId, string $variableId, Response $response, Database $dbForProject, Database $dbForConsole)
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

        $dbForProject->deleteDocument('variables', $variable->getId());

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $response->noContent();
    }
}
