<?php

namespace Appwrite\Platform\Modules\FunctionsVariables\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'list';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/:functionId/variables')
            ->desc('List variables')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.read')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'functions')
            ->label('sdk.method', 'listVariables')
            ->label('sdk.description', '/docs/references/functions/list-variables.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_VARIABLE_LIST)
            ->param('functionId', '', new UID(), 'Function unique ID.', false)
            ->inject('response')
            ->inject('dbForProject')
            ->callback(fn ($functionId, $response, $dbForProject) => $this->action($functionId, $response, $dbForProject));
    }

    public function action(string $functionId, Response $response, Database $dbForProject)
    {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'variables' => $function->getAttribute('vars', []),
            'total' => \count($function->getAttribute('vars', [])),
        ]), Response::MODEL_VARIABLE_LIST);
    }
}
