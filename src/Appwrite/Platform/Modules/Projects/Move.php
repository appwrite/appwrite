<?php

namespace Appwrite\Platform\Modules\Projects;

use Appwrite\Event\Realtime;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * Handing a project to another organization. Every path that moves a project, a direct move or a
 * claimed transfer, goes through moveProject() and then announceMove().
 */
trait Move
{
    /**
     * Give the project to the team, with the VCS records that carry its permissions.
     *
     * Writes several documents, so callers run it inside a transaction.
     *
     * @param array<string> $permissions the team's permissions on the project
     * @param array<string, mixed> $attributes more of the project to change with the move
     */
    protected function moveProject(Database $dbForPlatform, Document $project, Document $team, array $permissions, array $attributes = []): Document
    {
        $project = $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            ...$attributes,
            'teamId' => $team->getId(),
            'teamInternalId' => $team->getSequence(),
            '$permissions' => $permissions,
        ]));

        foreach (['installations', 'repositories', 'vcsComments'] as $collection) {
            $this->moveProjectDocuments($collection, $project, $permissions, $dbForPlatform);
        }

        return $project;
    }

    /**
     * Once the move has committed: drop the cached project and end the previous organization's tails
     * and admin-mode connections, as a team update does.
     */
    protected function announceMove(Database $dbForPlatform, Document $project, Document $team, Realtime $queueForRealtime): void
    {
        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $queueForRealtime
            ->setProject($project)
            ->setEvent('projects.[projectId].update.team')
            ->setParam('projectId', $project->getId())
            ->setPayload([
                '$id' => $project->getId(),
                'teamId' => $team->getId(),
            ])
            ->trigger();
    }

    /**
     * Give every document of the project in the collection the new organization's permissions.
     *
     * Pages through all of them, since a project can have more than one page of VCS comments.
     *
     * @param array<string> $permissions
     */
    private function moveProjectDocuments(string $collection, Document $project, array $permissions, Database $dbForPlatform): void
    {
        $limit = 100;
        $cursor = null;

        do {
            $queries = [
                Query::equal('projectInternalId', [$project->getSequence()]),
                Query::limit($limit),
            ];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $documents = $dbForPlatform->find($collection, $queries);

            foreach ($documents as $document) {
                $dbForPlatform->updateDocument($collection, $document->getId(), new Document(['$permissions' => $permissions]));
            }

            $cursor = \end($documents) ?: null;
        } while (\count($documents) === $limit);
    }
}
