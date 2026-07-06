<?php

namespace Appwrite\Platform\Modules\Functions\Http\Functions;

use Appwrite\Bus\Events\FunctionDeleted;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteFunction';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/functions/:functionId')
            ->desc('Delete function')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('audits.event', 'function.delete')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'functions',
                name: 'delete',
                description: <<<EOT
                Delete a function by its unique ID.
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
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('publisherForDeletes')
            ->inject('bus')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        Response $response,
        Database $dbForProject,
        DeletePublisher $publisherForDeletes,
        Bus $bus,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        Document $actor
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('functions', $function->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove function from DB');
        }

        // Inform scheduler to no longer run function
        $schedule = $dbForPlatform->getDocument('schedules', $function->getAttribute('scheduleId'));
        if (!$schedule->isEmpty()) {
            $schedule
                ->setAttribute('resourceUpdatedAt', DateTime::now())
                ->setAttribute('active', false);
            $authorization->skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
                'resourceUpdatedAt' => $schedule->getAttribute('resourceUpdatedAt'),
                'active' => $schedule->getAttribute('active'),
            ])));
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $function,
        ));

        $bus->dispatch(new FunctionDeleted($function, $project, $actor));

        $response->noContent();
    }
}
