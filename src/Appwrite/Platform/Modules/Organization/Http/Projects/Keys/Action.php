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
     * Resolve the project whose keys are managed and make sure it belongs to the organization.
     *
     * A key bound to one project must not reach the keys of a sibling project in the same organization.
     */
    protected function getProject(string $projectId, Document $team, Database $dbForPlatform, ?Key $apiKey): Document
    {
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

    /**
     * Blank the stored secret when the caller is an API key.
     */
    protected function withoutSecret(?Key $apiKey, Document $key): Document
    {
        if ($apiKey === null) {
            return $key;
        }

        $copy = new Document($key->getArrayCopy());
        $copy->setAttribute('secret', '');

        return $copy;
    }

    /**
     * @param array<Document> $keys
     * @return array<Document>
     */
    protected function withoutSecrets(?Key $apiKey, array $keys): array
    {
        if ($apiKey === null) {
            return $keys;
        }

        return \array_map(fn (Document $key) => $this->withoutSecret($apiKey, $key), $keys);
    }
}
