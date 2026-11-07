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
use Utopia\Database\Exception\Duplicate;
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
                Connect a GitHub installation that another project in the same organization already has to this project. The provider app is not installed again, so its repository access stays as it is.
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

        // GitLab, Gitea and Bitbucket need the source's OAuth pair on every call, and a copy of a rotating refresh token revokes the original
        if ($source->isEmpty() || $source->getAttribute('provider') !== 'github') {
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

        $providerInstallationId = $source->getAttribute('providerInstallationId');

        // One row per project and installation, so concurrent requests collide instead of creating duplicates
        $id = \substr(\hash('sha256', $project->getSequence() . ':' . $providerInstallationId), 0, 32);

        $installation = $dbForPlatform->findOne('installations', [
            Query::equal('providerInstallationId', [$providerInstallationId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('provider', ['github']),
        ]);

        if ($installation->isEmpty()) {
            // The OAuth pair is not copied: repository creation refreshes it, and the rotation would revoke the source's copy.
            // Without it, personal installations fall back to the caller's GitHub identity.
            try {
                $installation = $dbForPlatform->createDocument('installations', new Document([
                    '$id' => $id,
                    '$permissions' => $this->getPermissions($project->getAttribute('teamId', ''), $project->getId()),
                    'providerInstallationId' => $providerInstallationId,
                    'projectId' => $project->getId(),
                    'projectInternalId' => $project->getSequence(),
                    'provider' => 'github',
                    'organization' => $source->getAttribute('organization'),
                    'personal' => $source->getAttribute('personal', false),
                ]));
            } catch (Duplicate) {
                $installation = $dbForPlatform->getDocument('installations', $id);
            }
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
