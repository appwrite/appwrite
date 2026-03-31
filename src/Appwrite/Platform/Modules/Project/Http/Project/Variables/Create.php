<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Variables;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createProjectVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/project/variables')
            ->desc('Create project variable')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'variables.[variableId].create')
            ->label('audits.event', 'project.variable.create')
            ->label('audits.resource', 'project.variable/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'variables',
                name: 'createVariable',
                description: <<<EOT
                Create a new project environment variable. These variables can be accessed by all functions and sites in the project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_VARIABLE,
                    )
                ],
            ))
            ->param('variableId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Variable ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('key', null, new Text(Database::LENGTH_KEY), 'Variable key. Max length: ' . Database::LENGTH_KEY  . ' chars.')
            ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.')
            ->param('secret', true, new Boolean(), 'Secret variables can be updated or deleted, but only projects can read them during build and runtime.', true)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $variableId,
        string $key,
        string $value,
        bool $secret,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForProject,
    ) {
        $variableId = ($variableId == 'unique()') ? ID::unique() : $variableId;

        $variable = new Document([
            '$id' => $variableId,
            '$permissions' => [],
            'resourceInternalId' => '', // Already in project DB anyway
            'resourceId' => '', // Already in project DB anyway
            'resourceType' => 'project',
            'key' => $key,
            'value' => $value,
            'secret' => $secret,
            'search' => implode(' ', [$variableId, $key, 'project']),
        ]);

        try {
            $variable = $dbForProject->createDocument('variables', $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        foreach (['functions', 'sites'] as $collection) {
            $dbForProject->updateDocuments($collection, new Document([
                'live' => false
            ]));
        }

        $queueForEvents->setParam('variableId', $variable->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
