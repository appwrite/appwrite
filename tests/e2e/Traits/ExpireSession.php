<?php

declare(strict_types=1);

namespace Tests\E2E\Traits;

use Swoole\Coroutine;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

use function Swoole\Coroutine\run;

trait ExpireSession
{
    /**
     * Move a session's expiry into the past so the list endpoints' default exclusion and an
     * explicit expire filter can be exercised without waiting out the minimum session duration.
     */
    protected function expireSession(string $sessionId, int $secondsAgo = 3600): void
    {
        $expire = function () use ($sessionId, $secondsAgo): void {
            global $register;
            $authorization = new Authorization();
            $factory = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization);

            $authorization->skip(function () use ($factory, $sessionId, $secondsAgo): void {
                $dbForProject = $factory->project($factory->platform()->getDocument('projects', $this->getProject()['$id']));
                $dbForProject->updateDocument('sessions', $sessionId, new Document([
                    'expire' => DateTime::addSeconds(new \DateTime(), -$secondsAgo),
                ]));
                $dbForProject->purgeCachedDocument('sessions', $sessionId);
            });
        };

        if (Coroutine::getCid() >= 0) {
            $expire();
        } else {
            run($expire);
        }
    }
}
