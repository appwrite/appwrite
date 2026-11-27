<?php

namespace Appwrite\Platform\Modules\Organization\Http\Projects\Keys;

use Appwrite\Auth\Key;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
use Utopia\Database\Database;
use Utopia\Database\Document;

class Action extends AppwriteAction
{
    /**
     * Organization scopes for managing a project's keys. The routes also accept the project scopes
     * `keys.read` and `keys.write`, which only a project key may use (see getProject()).
     */
    protected const string SCOPE_READ = 'organization.projects.keys.read';

    protected const string SCOPE_WRITE = 'organization.projects.keys.write';

    /**
     * Resolve the project whose keys are managed and make sure it belongs to the organization.
     *
     * A key bound to one project must not reach the keys of a sibling project in the same organization.
     *
     * The routes also accept the project scopes `keys.read` and `keys.write`, so a project key can manage
     * its own project's keys. An organization key's `keys.*` scopes are a different grant (its own
     * organization's keys), so an organization key needs the organization scope `$scope` here.
     */
    protected function getProject(string $projectId, Document $team, Database $dbForPlatform, ?Key $apiKey, string $scope): Document
    {
        if ($apiKey !== null && $apiKey->getType() === API_KEY_ORGANIZATION && !\in_array($scope, $apiKey->getScopes(), true)) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'Organization key missing scope (' . $scope . ')');
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty() || $project->getId() === 'console') {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if ($project->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if ($apiKey !== null && !$apiKey->isProjectCheckDisabled() && $apiKey->getProjectId() !== '' && $apiKey->getProjectId() !== $project->getId()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        return $project;
    }
}
