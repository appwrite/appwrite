<?php

namespace Appwrite\Platform\Modules\VCS\Http\Requests;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listRequests';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/requests')
            ->desc('List installation requests')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'requests',
                name: 'listRequests',
                description: '/docs/references/vcs/list-requests.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSTALLATION_REQUEST_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Document $project,
        Database $dbForPlatform
    ) {
        $queries = [Query::equal('projectInternalId', [$project->getSequence()])];

        $response->dynamic(new Document([
            'requests' => $dbForPlatform->find('installationRequests', $queries),
            'total' => $dbForPlatform->count('installationRequests', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_INSTALLATION_REQUEST_LIST);
    }
}
