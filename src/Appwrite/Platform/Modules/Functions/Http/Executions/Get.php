<?php

namespace Appwrite\Platform\Modules\Functions\Http\Executions;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getExecution';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/:functionId/executions/:executionId')
            ->desc('Get execution')
            ->groups(['api', 'functions'])
            ->label('scope', 'execution.read')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'executions',
                name: 'getExecution',
                description: <<<EOT
                Get a function execution log by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EXECUTION,
                    )
                ]
            ))
            ->param('functionId', '', new UID(), 'Function ID.')
            ->param('executionId', '', new UID(), 'Execution ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $executionId,
        Response $response,
        Database $dbForProject,
        Authorization $authorization
    ) {
        $function = $authorization->skip(fn () => $dbForProject->getDocument('functions', $functionId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($function->isEmpty() || (!$function->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $execution = $dbForProject->getDocument('executions', $executionId);

        if ($execution->getAttribute('resourceType') !== 'functions' || $execution->getAttribute('resourceInternalId') !== $function->getSequence()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        if ($execution->isEmpty()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        $response->dynamic($execution, Response::MODEL_EXECUTION);
    }
}
