<?php

namespace Appwrite\Platform\Modules\Sites\Http\Variables;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/sites/:siteId/variables')
            ->desc('Create variable')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('audits.event', 'variable.create')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'variables',
                name: 'createVariable',
                description: <<<EOT
                Create a new site variable. These variables can be accessed during build and runtime (server-side rendering) as environment variables.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_VARIABLE,
                    )
                ]
            ))
            ->param('siteId', '', new UID(), 'Site unique ID.', false)
            ->param('key', null, new Text(Database::LENGTH_KEY), 'Variable key. Max length: ' . Database::LENGTH_KEY  . ' chars.', false)
            ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', false)
            ->param('secret', true, new Boolean(), 'Secret variables can be updated or deleted, but only sites can read them during build and runtime.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(string $siteId, string $key, string $value, bool $secret, Response $response, Database $dbForProject, Document $project)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $variableId = ID::unique();

        $teamId = $project->getAttribute('teamId', '');
        $variable = new Document([
            '$id' => $variableId,
            '$permissions' => [
                Permission::read(Role::team(ID::custom($teamId))),
                Permission::update(Role::team(ID::custom($teamId), 'owner')),
                Permission::update(Role::team(ID::custom($teamId), 'developer')),
                Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                Permission::delete(Role::team(ID::custom($teamId), 'developer')),
            ],
            'resourceInternalId' => $site->getSequence(),
            'resourceId' => $site->getId(),
            'resourceType' => 'site',
            'key' => $key,
            'value' => $value,
            'secret' => $secret,
            'search' => implode(' ', [$variableId, $site->getId(), $key, 'site']),
        ]);

        try {
            $variable = $dbForProject->createDocument('variables', $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $dbForProject->updateDocument('sites', $site->getId(), $site->setAttribute('live', false));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
