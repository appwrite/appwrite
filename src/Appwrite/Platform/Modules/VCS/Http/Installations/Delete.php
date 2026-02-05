<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations;

use Appwrite\Event\Delete as DeleteEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteInstallation';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/vcs/installations/:installationId')
            ->desc('Delete installation')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.write')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'installations',
                name: 'deleteInstallation',
                description: '/docs/references/vcs/delete-installation.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('queueForDeletes')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        Response $response,
        Database $dbForPlatform,
        DeleteEvent $queueForDeletes
    ) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!$dbForPlatform->deleteDocument('installations', $installation->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove installation from DB');
        }

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($installation);

        $response->noContent();
    }
}
