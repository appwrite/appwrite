<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Variables;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/variables/:variableId')
            ->desc('Get project variable')
            ->groups(['api', 'project'])
            ->label('scope', 'project.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'variables',
                name: 'getVariable',
                description: <<<EOT
                Get a variable by its unique ID. 
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VARIABLE,
                    )
                ]
            ))
            ->param('variableId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Variable ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $variableId,
        Response $response,
        Database $dbForProject,
    ) {
        $variable = $dbForProject->getDocument('variables', $variableId);

        if ($variable->isEmpty() || $variable->getAttribute('resourceType', '') !== 'project') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
