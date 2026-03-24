<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Variables;

use Appwrite\Event\Event;
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
        return 'deleteProjectVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/project/variables/:variableId')
            ->desc('Delete project variable')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'variables.[variableId].delete')
            ->label('audits.event', 'project.variable.delete')
            ->label('audits.resource', 'project.variable/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
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
            ->param('variableId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Variable ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $variableId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
    ) {
        $variable = $dbForProject->getDocument('variables', $variableId);

        if ($variable->isEmpty() || $variable->getAttribute('resourceType', '') !== 'project') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('variables', $variable->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove document from DB');
        };

        foreach (['functions', 'sites'] as $collection) {
            $dbForProject->updateDocuments($collection, new Document([
                'live' => false
            ]));
        }

        $queueForEvents->setParam('variableId', $variable->getId());

        $response->noContent();
    }
}
