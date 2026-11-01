<?php

namespace Appwrite\Screenshots;

use Appwrite\Permission;
use Appwrite\Role;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;

/**
 * A site deployment's screenshots are files in the console's `screenshots` bucket, readable only by the team that
 * owns the project. That team changes when the project moves, so the files are granted to it again.
 */
final readonly class Access
{
    public const string BUCKET = 'screenshots';

    private const array ATTRIBUTES = ['screenshotLight', 'screenshotDark'];

    public function __construct(
        private Database $dbForPlatform,
    ) {
    }

    /**
     * Make every screenshot of the project's site deployments readable by the team that owns the project now, and
     * by no other. The team is read from the primary, and again at the end: a move that lands while this runs makes
     * it start over, so it never leaves the files with a team the project has already left.
     *
     * @param bool $commit False only counts the files still granted to another team
     * @return int The number of files that were granted to another team
     */
    public function grant(Database $dbForProject, Document $project, bool $commit = true): int
    {
        $bucket = $this->bucket();

        if ($bucket->isEmpty()) {
            return 0;
        }

        do {
            $teamId = $this->team($project);
            if ($teamId === '') {
                return 0;
            }

            // A generator reads lazily, so the whole walk runs inside the skip
            $count = $dbForProject->getAuthorization()->skip(function () use ($dbForProject, $bucket, $teamId, $commit): int {
                $count = 0;
                $ids = [];
                foreach ($dbForProject->cursor('deployments', [
                    Query::equal('resourceType', ['sites']),
                    Query::select(self::ATTRIBUTES),
                ]) as $deployment) {
                    \array_push($ids, ...$this->files($deployment));

                    if (\count($ids) >= APP_DATABASE_QUERY_MAX_VALUES) {
                        $count += $this->update($bucket, $teamId, $ids, $commit);
                        $ids = [];
                    }
                }

                if ($ids !== []) {
                    $count += $this->update($bucket, $teamId, $ids, $commit);
                }

                return $count;
            });
        } while ($commit && $this->team($project) !== $teamId);

        return $count;
    }

    /**
     * The same for one deployment's screenshots, once its capture has stored them: the capture was queued with the
     * project as it was then, and a move since may already have passed the deployment.
     *
     * @return int The number of files that were granted to another team
     */
    public function grantDeployment(Database $dbForProject, Document $project, string $deploymentId): int
    {
        $bucket = $this->bucket();
        $deployment = $dbForProject->getAuthorization()->skip(fn (): Document => $dbForProject->getDocument('deployments', $deploymentId, [Query::select(self::ATTRIBUTES)]));
        $ids = $this->files($deployment);

        if ($bucket->isEmpty() || $ids === []) {
            return 0;
        }

        $count = 0;
        do {
            $teamId = $this->team($project);
            if ($teamId === '') {
                return $count;
            }

            $count += $this->update($bucket, $teamId, $ids, true);
        } while ($this->team($project) !== $teamId);

        return $count;
    }

    private function bucket(): Document
    {
        return $this->dbForPlatform->getAuthorization()->skip(fn (): Document => $this->dbForPlatform->getDocument('buckets', self::BUCKET));
    }

    /**
     * The project's team as the primary holds it, past any cache or replica still serving the one before a move.
     */
    private function team(Document $project): string
    {
        return $this->dbForPlatform->getAuthorization()->skip(
            fn (): Document => $this->dbForPlatform->getDocument('projects', $project->getId(), [Query::select(['teamId'])], forUpdate: true)
        )->getAttribute('teamId', '');
    }

    /**
     * @return list<string>
     */
    private function files(Document $deployment): array
    {
        $ids = [];
        foreach (self::ATTRIBUTES as $attribute) {
            $id = $deployment->getAttribute($attribute, '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $ids
     */
    private function update(Document $bucket, string $teamId, array $ids, bool $commit): int
    {
        $collection = 'bucket_' . $bucket->getSequence();
        $permissions = [Permission::read(Role::team(Id::custom($teamId)))];

        return $this->dbForPlatform->getAuthorization()->skip(function () use ($collection, $permissions, $ids, $commit): int {
            $stale = [];
            foreach ($this->dbForPlatform->find($collection, [
                Query::equal('$id', $ids),
                Query::select(['$id', '$permissions']),
                Query::limit(\count($ids)),
            ]) as $file) {
                if ($file->getPermissions() !== $permissions) {
                    $stale[] = $file->getId();
                }
            }

            if ($commit && $stale !== []) {
                $this->dbForPlatform->updateDocuments($collection, new Document(['$permissions' => $permissions]), [
                    Query::equal('$id', $stale),
                ]);
            }

            return \count($stale);
        });
    }
}
