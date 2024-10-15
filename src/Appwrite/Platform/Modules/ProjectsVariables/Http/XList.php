<?php

namespace Appwrite\Platform\Modules\ProjectsVariables\Http;

use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
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
            ->setHttpPath('/v1/project/variables')
            ->desc('List variables')
            ->groups(['api'])
            ->label('scope', 'projects.read')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'project')
            ->label('sdk.method', 'listVariables')
            ->label('sdk.description', '/docs/references/project/list-variables.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_VARIABLE_LIST)
            ->inject('response')
            ->inject('dbForProject')
            ->callback(fn ($response, $dbForProject) => $this->action($response, $dbForProject));
    }

    public function action(Response $response, Database $dbForProject)
    {
        $variables = $dbForProject->find('variables', [
            Query::equal('resourceType', ['project']),
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        $response->dynamic(new Document([
            'variables' => $variables,
            'total' => \count($variables),
        ]), Response::MODEL_VARIABLE_LIST);
    }
}
