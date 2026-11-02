<?php

namespace Appwrite\Platform\Modules\Organization\Http\Transfers;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteProjectTransfer';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/organization/transfers/:transferId')
            ->desc('Delete project transfer')
            ->groups(['api', 'organization'])
            ->label('scope', 'projects.write')
            ->label('audits.event', 'transfer.delete')
            ->label('audits.resource', 'transfer/{request.transferId}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'transfers',
                name: 'deleteProjectTransfer',
                description: <<<EOT
                Delete a project transfer, so its secret can no longer be used to claim the project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('transferId', '', new UID(), 'Transfer ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $transferId,
        Response $response,
        Database $dbForPlatform,
        Document $team,
        Authorization $authorization,
    ) {
        $transfer = $this->getOwnTransfer($transferId, $team, $dbForPlatform, $authorization);

        if (!$authorization->skip(fn () => $dbForPlatform->deleteDocument('transfers', $transfer->getId()))) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove document from DB');
        }

        $response->noContent();
    }
}
