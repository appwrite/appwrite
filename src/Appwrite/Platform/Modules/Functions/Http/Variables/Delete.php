<?php

namespace Appwrite\Platform\Modules\Functions\Http\Variables;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/functions/:functionId/variables/:variableId')
            ->desc('Delete variable')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('event', 'variables.[variableId].delete')
            ->label('audits.event', 'variable.delete')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'variables',
                name: 'deleteVariable',
                description: <<<EOT
                Delete a variable by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function unique ID.', false, ['dbForProject'])
            ->param('variableId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Variable unique ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $variableId,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForProject
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable->isEmpty() || $variable->getAttribute('resourceInternalId') !== $function->getSequence() || $variable->getAttribute('resourceType') !== 'function') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $dbForProject->deleteDocument('variables', $variable->getId());

        $function->setAttribute('live', false);
        $dbForProject->updateDocument('functions', $function->getId(), new Document(['live' => false]));

        $queueForEvents->setParam('variableId', $variable->getId());

        $response->noContent();
    }
}
