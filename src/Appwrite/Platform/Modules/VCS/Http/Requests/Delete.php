<?php

namespace Appwrite\Platform\Modules\VCS\Http\Requests;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteRequest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/vcs/requests/:requestId')
            ->desc('Delete installation request')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.write')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'requests',
                name: 'deleteRequest',
                description: '/docs/references/vcs/delete-request.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('requestId', '', new Text(256), 'Installation request Id')
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $requestId,
        Response $response,
        Document $project,
        Database $dbForPlatform
    ) {
        $request = $dbForPlatform->getDocument('installationRequests', $requestId);

        if ($request->isEmpty() || $request->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::INSTALLATION_REQUEST_NOT_FOUND);
        }

        $dbForPlatform->deleteDocument('installationRequests', $request->getId());

        $response->noContent();
    }
}
