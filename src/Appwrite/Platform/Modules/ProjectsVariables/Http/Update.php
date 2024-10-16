<?php

namespace Appwrite\Platform\Modules\ProjectsVariables\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Query;
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
            ->setHttpPath('/v1/project/variables/:variableId')
            ->desc('Update variable')
            ->groups(['api'])
            ->label('scope', 'projects.write')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'project')
            ->label('sdk.method', 'updateVariable')
            ->label('sdk.description', '/docs/references/project/update-variable.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_VARIABLE)
            ->param('variableId', '', new UID(), 'Variable unique ID.', false)
            ->param('key', null, new Text(255), 'Variable key. Max length: 255 chars.', false)
            ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', true)
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForConsole')
            ->callback(fn ($variableId, $key, $value, $project, $response, $dbForProject, $dbForConsole) => $this->action($variableId, $key, $value, $project, $response, $dbForProject, $dbForConsole));
    }

    public function action(string $variableId, string $key, ?string $value, Document $project, Response $response, Database $dbForProject, Database $dbForConsole)
    {
        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceType') !== 'project') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $variable
            ->setAttribute('key', $key)
            ->setAttribute('value', $value ?? $variable->getAttribute('value'))
            ->setAttribute('search', implode(' ', [$variableId, $key, 'project']));

        try {
            $dbForProject->updateDocument('variables', $variable->getId(), $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $functions = $dbForProject->find('functions', [
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        foreach ($functions as $function) {
            $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
