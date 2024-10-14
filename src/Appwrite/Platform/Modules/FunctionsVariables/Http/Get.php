<?php

namespace Appwrite\Platform\Modules\FunctionsVariables\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'get';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/:functionId/variables/:variableId')
            ->desc('Get variable')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.read')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'functions')
            ->label('sdk.method', 'getVariable')
            ->label('sdk.description', '/docs/references/functions/get-variable.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_VARIABLE)
            ->param('functionId', '', new UID(), 'Function unique ID.', false)
            ->param('variableId', '', new UID(), 'Variable unique ID.', false)
            ->inject('response')
            ->inject('dbForProject')
            ->callback(fn ($functionId, $variableId, $response, $dbForProject) => $this->action($functionId, $variableId, $response, $dbForProject));
    }

    public function action(string $functionId, string $variableId, Response $response, Database $dbForProject)
    {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);
        if (
            $variable === false ||
            $variable->isEmpty() ||
            $variable->getAttribute('resourceInternalId') !== $function->getInternalId() ||
            $variable->getAttribute('resourceType') !== 'function'
        ) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable === false || $variable->isEmpty()) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
