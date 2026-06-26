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
 * whole attribute.
 *
 * The injected $project is resolved through the cache and can be stale (a
 * concurrent reader may have re-cached a pre-commit copy after a previous
 * update purged it). On adapters that support it (SQL), we therefore re-read
 * the project with a cache-skipping `forUpdate` read so the map is rebuilt from
 * the committed row rather than a poisoned cache entry.
 *
 * We deliberately do NOT wrap updateDocument in our own transaction: that would
 * nest updateDocument's transaction and push its post-commit cache purge ahead
 * of the real commit, leaving a window for a concurrent read to re-cache the
 * stale row. Letting updateDocument own the transaction keeps its purge-after-
 * commit behaviour intact.
 *
 * MongoDB has no FOR UPDATE lock (getSupportForUpdateLock() === false) and its
 * getDocument ignores $forUpdate, so the read buys nothing; there we keep the
 * original behaviour and operate on the request-scoped project document.
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
        $current = $project;

        if ($dbForPlatform->getAdapter()->getSupportForUpdateLock()) {
            $current = $authorization->skip(
                fn () => $dbForPlatform->getDocument('projects', $project->getId(), forUpdate: true)
            );
        }

        return $authorization->skip(fn () => $callback($current));
    }
}
