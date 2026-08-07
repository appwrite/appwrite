<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
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
        return 'getDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/:functionId/deployments/:deploymentId')
            ->desc('Get deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.read')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'deployments',
                name: 'getDeployment',
                description: <<<EOT
                Get a function deployment by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ]
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $deploymentId,
        Response $response,
        Database $dbForProject
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $resourceType = $deployment->getAttribute('resourceType');
        $ownsDeployment = false;
        if ($deployment->getAttribute('resourceId') === $function->getId()) {
            if ($resourceType === 'functions') {
                $ownsDeployment = true;
            } elseif (empty($resourceType) && $deployment->getAttribute('resourceInternalId') === $function->getSequence()) {
                // Sequences are per-collection. An opposite-type resource with the same
                // public ID is fine unless its sequence also matches, which would make
                // an untyped deployment ambiguous across namespaces.
                $opposite = $dbForProject->getAuthorization()->skip(
                    fn () => $dbForProject->getDocument('sites', $function->getId())
                );
                $ownsDeployment = $opposite->isEmpty() || $opposite->getSequence() !== $function->getSequence();
            }
        }
        if (!$ownsDeployment) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
