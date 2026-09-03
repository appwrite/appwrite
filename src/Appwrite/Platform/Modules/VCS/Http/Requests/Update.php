<?php

namespace Appwrite\Platform\Modules\VCS\Http\Requests;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;
    use AppwritePermission;

    public static function getName()
    {
        return 'updateRequest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/vcs/requests/:requestId')
            ->desc('Update installation request')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.write')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'requests',
                name: 'updateRequest',
                description: '/docs/references/vcs/update-request.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSTALLATION,
                    )
                ]
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

        if ($request->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_REQUEST_NOT_FOUND);
        }

        if ($request->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::INSTALLATION_REQUEST_NOT_FOUND);
        }

        if ($request->getAttribute('status') !== 'ready') {
            throw new Exception(Exception::INSTALLATION_REQUEST_NOT_READY);
        }

        // Consuming the request first makes it the lock: a concurrent confirm,
        // or an uninstall sweep that already removed it, stops here.
        if (!$dbForPlatform->deleteDocument('installationRequests', $request->getId())) {
            throw new Exception(Exception::INSTALLATION_REQUEST_NOT_FOUND);
        }

        $provider = $request->getAttribute('provider');
        $providerInstallationId = $request->getAttribute('providerInstallationId');

        try {
            $installation = $dbForPlatform->findOne('installations', [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('projectInternalId', [$project->getSequence()]),
                Query::equal('provider', [$provider]),
            ]);

            if ($installation->isEmpty()) {
                $installation = $dbForPlatform->createDocument('installations', new Document([
                    '$id' => ID::unique(),
                    '$permissions' => $this->getPermissions($project->getAttribute('teamId', ''), $project->getId()),
                    'providerInstallationId' => $providerInstallationId,
                    'projectId' => $project->getId(),
                    'projectInternalId' => $project->getSequence(),
                    'provider' => $provider,
                    'organization' => $request->getAttribute('organization'),
                    'personal' => false,
                ]));
            }
        } catch (\Throwable $th) {
            // The approval must survive a failed creation, so the consumed
            // request goes back for another try. A restore that fails too must
            // not hide why the confirmation failed in the first place.
            try {
                $dbForPlatform->createDocument('installationRequests', $request);
            } catch (\Throwable $restore) {
                Console::error('Failed to restore installation request ' . $request->getId() . ': ' . $restore->getMessage());
            }

            throw $th;
        }


        $response->dynamic($installation, Response::MODEL_INSTALLATION);
    }
}
