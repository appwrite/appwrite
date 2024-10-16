<?php

namespace Appwrite\Platform\Modules\ProjectsVariables\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
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
            ->setHttpPath('/v1/project/variables')
            ->desc('Create variable')
            ->groups(['api'])
            ->label('scope', 'projects.write')
            ->label('audits.event', 'variable.create')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'project')
            ->label('sdk.method', 'createVariable')
            ->label('sdk.description', '/docs/references/project/create-variable.md')
            ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_VARIABLE)
            ->param('key', null, new Text(Database::LENGTH_KEY), 'Variable key. Max length: ' . Database::LENGTH_KEY  . ' chars.', false)
            ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', false)
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForConsole')
            ->callback(fn ($key, $value, $project, $response, $dbForProject, $dbForConsole) => $this->action($key, $value, $project, $response, $dbForProject, $dbForConsole));
    }

    public function action(string $key, string $value, Document $project, Response $response, Database $dbForProject, Database $dbForConsole)
    {
        $variableId = ID::unique();

        $variable = new Document([
            '$id' => $variableId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceInternalId' => '',
            'resourceId' => '',
            'resourceType' => 'project',
            'key' => $key,
            'value' => $value,
            'search' => implode(' ', [$variableId, $key, 'project']),
        ]);

        try {
            $variable = $dbForProject->createDocument('variables', $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $functions = $dbForProject->find('functions', [
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        foreach ($functions as $function) {
            $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
