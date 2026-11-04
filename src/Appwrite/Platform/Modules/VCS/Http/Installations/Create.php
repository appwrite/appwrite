<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;
    use AppwritePermission;

    public static function getName()
    {
        return 'createInstallation';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/installations')
            ->desc('Create installation')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.write')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'installations',
                name: 'createInstallation',
                description: <<<EOT
                Connect a version control installation that another project in the same organization already has to this project. The provider app is not installed again, so its repository access stays as it is.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_INSTALLATION,
                    )
                ]
            ))
            ->param('installationId', '', new Text(256), 'ID of the installation to reuse, from another project in the same organization.')
            ->inject('vcsFactory')
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        VcsFactory $vcsFactory,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        User $user,
        Authorization $authorization
    ) {
        $source = $dbForPlatform->getDocument('installations', $installationId);

        if ($source->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $sourceProject = $dbForPlatform->getDocument('projects', $source->getAttribute('projectId', ''));

        // Admin requests run with authorization disabled, so check the caller can read the source project
        if (
            $sourceProject->isEmpty()
            || $sourceProject->getAttribute('teamInternalId') !== $project->getAttribute('teamInternalId')
            || empty(\array_intersect($sourceProject->getPermissionsByType(PermissionType::Read), $user->getRoles($authorization)))
        ) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installation = $dbForPlatform->findOne('installations', [
            Query::equal('providerInstallationId', [$source->getAttribute('providerInstallationId')]),
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('provider', [$source->getAttribute('provider')]),
        ]);

        if ($installation->isEmpty()) {
            $installation = $dbForPlatform->createDocument('installations', new Document([
                '$id' => Id::unique(),
                '$permissions' => $this->getPermissions($project->getAttribute('teamId', ''), $project->getId()),
                'providerInstallationId' => $source->getAttribute('providerInstallationId'),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getSequence(),
                'provider' => $source->getAttribute('provider'),
                'organization' => $source->getAttribute('organization'),
                'personal' => $source->getAttribute('personal', false),
                'personalRefreshToken' => $source->getAttribute('personalRefreshToken'),
                'personalAccessToken' => $source->getAttribute('personalAccessToken'),
                'personalAccessTokenExpiry' => $source->getAttribute('personalAccessTokenExpiry'),
            ]));
        }

        $provider = $installation->getAttribute('provider', '');
        if ($vcsFactory->isConfigured($provider)) {
            $installation->setAttribute('organizationUrl', $vcsFactory->fromProviderForBrowser($provider)->getOrganizationUrl($installation->getAttribute('organization', '')));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($installation, Response::MODEL_INSTALLATION);
    }
}
