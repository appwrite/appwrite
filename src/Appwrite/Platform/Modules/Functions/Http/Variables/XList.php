<?php

namespace Appwrite\Platform\Modules\Functions\Http\Variables;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listVariables';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/:functionId/variables')
            ->desc('List variables')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.read')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label(
                'sdk',
                new Method(
                    namespace: 'functions',
                    group: 'variables',
                    name: 'listVariables',
                    description: <<<EOT
                    Get a list of all variables of a specific function.
                    EOT,
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_VARIABLE_LIST,
                        )
                    ],
                )
            )
            ->param('functionId', '', new UID(), 'Function unique ID.', false)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $functionId, Response $response, Database $dbForProject)
    {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'variables' => $function->getAttribute('vars', []),
            'total' => \count($function->getAttribute('vars', [])),
        ]), Response::MODEL_VARIABLE_LIST);
    }
}
