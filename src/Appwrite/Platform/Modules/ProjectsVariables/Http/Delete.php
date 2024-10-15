<?php

namespace Appwrite\Platform\Modules\ProjectsVariables\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
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
            ->setHttpPath('/v1/project/variables/:variableId')
            ->desc('Delete variable')
            ->groups(['api'])
            ->label('scope', 'projects.write')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'project')
            ->label('sdk.method', 'deleteVariable')
            ->label('sdk.description', '/docs/references/project/delete-variable.md')
            ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
            ->label('sdk.response.model', Response::MODEL_NONE)
            ->param('variableId', '', new UID(), 'Variable unique ID.', false)
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->callback(fn ($variableId, $project, $response, $dbForProject) => $this->action($variableId, $project, $response, $dbForProject));
    }

    public function action(string $variableId, Document $project, Response $response, Database $dbForProject)
    {
        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceType') !== 'project') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $dbForProject->deleteDocument('variables', $variable->getId());

        $functions = $dbForProject->find('functions', [
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        foreach ($functions as $function) {
            $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));
        }

        $response->noContent();
    }
}
