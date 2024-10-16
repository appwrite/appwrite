<?php

namespace Appwrite\Platform\Modules\ProjectsVariables\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
            ->setHttpPath('/v1/project/variables/:variableId')
            ->desc('Get variable')
            ->groups(['api'])
            ->label('scope', 'projects.read')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'project')
            ->label('sdk.method', 'getVariable')
            ->label('sdk.description', '/docs/references/project/get-variable.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_VARIABLE)
            ->param('variableId', '', new UID(), 'Variable unique ID.', false)
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->callback(fn ($variableId, $response, $project, $dbForProject) => $this->action($variableId, $response, $project, $dbForProject));
    }

    public function action(string $variableId, Response $response, Document $project, Database $dbForProject)
    {
        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceType') !== 'project') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
