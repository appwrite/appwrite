<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\GitHub\Callback;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getGitHubCallback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/github/callback')
            ->desc('Create installation document using GitHub installation id')
            ->groups(['mock', 'api', 'vcs'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->param('providerInstallationId', '', new UID(), 'GitHub installation ID')
            ->param('projectId', '', new UID(), 'Project ID of the project where app is to be installed')
            ->inject('vcsFactory')
            ->inject('project')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $providerInstallationId, string $projectId, VcsFactory $vcsFactory, Document $project, Response $response, Database $dbForPlatform): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $error = 'Project with the ID from state could not be found.';
            throw new Exception(Exception::PROJECT_NOT_FOUND, $error);
        }

        if (empty($providerInstallationId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing provider installation ID');
        }

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => 'github',
            'providerInstallationId' => $providerInstallationId,
        ]));
        $owner = $vcs->getOwnerName($providerInstallationId);

        $projectInternalId = $project->getSequence();

        $teamId = $project->getAttribute('teamId', '');

        $installation = new Document([
            '$id' => Id::unique(),
            '$permissions' => [
                Permission::read(Role::team(Id::custom($teamId))),
                Permission::update(Role::team(Id::custom($teamId), 'owner')),
                Permission::update(Role::team(Id::custom($teamId), 'developer')),
                Permission::delete(Role::team(Id::custom($teamId), 'owner')),
                Permission::delete(Role::team(Id::custom($teamId), 'developer')),
            ],
            'providerInstallationId' => $providerInstallationId,
            'projectId' => $projectId,
            'projectInternalId' => $projectInternalId,
            'provider' => 'github',
            'organization' => $owner,
            'personal' => false
        ]);

        $installation = $dbForPlatform->createDocument('installations', $installation);

        $response->json([
            'installationId' => $installation->getId(),
        ]);
    }
}
