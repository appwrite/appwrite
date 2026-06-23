<?php

namespace Appwrite\Platform\Modules\Project\Http\Project;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

/**
 * Shared helper for project endpoints that read-modify-write a JSON map
 * attribute (services, auths, apis, smtp, templates, oAuthProviders, ...).
 *
 * Such a map must not be rebuilt from a cached/stale copy of the project and
 * written back wholesale, or a concurrent (or rapidly sequential) update to a
 * sibling key gets clobbered — the value handed to updateDocument replaces the
 * whole attribute. On adapters that support row locks (SQL), we re-read the
 * project fresh under a FOR UPDATE lock inside a transaction so the read,
 * mutation and write are atomic and never served from a poisoned cache.
 *
 * MongoDB has no FOR UPDATE lock (getSupportForUpdateLock() === false) and its
 * getDocument ignores $forUpdate, so wrapping it buys nothing; there we keep
 * the original behaviour and operate on the request-scoped project document.
 */
trait UpdatesProject
{
    /**
     * @param callable(Document):Document $callback receives the project to
     *        mutate and must return the result of updateDocument().
     */
    protected function updateProjectDocument(
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        callable $callback
    ): Document {
        if ($dbForPlatform->getAdapter()->getSupportForUpdateLock()) {
            return $authorization->skip(fn () => $dbForPlatform->withTransaction(
                fn () => $callback($dbForPlatform->getDocument('projects', $project->getId(), forUpdate: true))
            ));
        }

        return $authorization->skip(fn () => $callback($project));
    }
}
