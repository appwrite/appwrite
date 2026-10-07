<?php

namespace Appwrite\Schedule;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Extend\Exception;
use Closure;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Lock\Exception\Contention;

/**
 * Coordinates publication and cancellation of one scheduled execution.
 *
 * Claim, queue publication, cleanup, and a failed publish's restore share
 * one lock with cancellation. Cancellation deletes the schedule only when
 * that publication was prevented. Failing to acquire the lock is retryable
 * and is not treated as losing to a committed publication.
 */
final class Execution
{
    private const int LOCK_TTL = 30;

    private const float LOCK_TIMEOUT = 10.0;

    /**
     * @param Closure(string, int, callable, float=): mixed $locks
     */
    public function __construct(private readonly Closure $locks)
    {
    }

    /**
     * Claim the schedule, publish it, and remove the row.
     *
     * False when the schedule is already gone or inactive. A failed publish
     * restores `active` before the lock is released, so a cancellation that
     * was waiting can still delete it.
     *
     * @param callable(FunctionMessage): mixed $enqueue
     */
    public function publish(Database $dbForPlatform, Document $project, Document $execution, string $functionId, callable $enqueue): bool
    {
        $scheduleId = $execution->getAttribute('scheduleId', '');

        $enqueued = $this->hold($scheduleId, function () use ($dbForPlatform, $project, $execution, $functionId, $enqueue, $scheduleId): bool {
            $schedule = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $scheduleId) {
                $schedule = $dbForPlatform->getDocument('schedules', $scheduleId, forUpdate: true);

                if ($schedule->isEmpty() || !$schedule->getAttribute('active', false)) {
                    return new Document();
                }

                $claimed = $dbForPlatform->updateDocument('schedules', $scheduleId, new Document([
                    'resourceUpdatedAt' => DateTime::now(),
                    'active' => false,
                ]));

                return $claimed->isEmpty() ? new Document() : $schedule;
            });

            if ($schedule->isEmpty()) {
                return false;
            }

            $data = $schedule->getAttribute('data', []);
            $functionId = $data['functionId'] ?? $functionId;

            if (empty($functionId)) {
                Console::error("Missing functionId for scheduled execution {$execution->getId()}, skipping");
                $dbForPlatform->deleteDocument('schedules', $scheduleId);
                return false;
            }

            $published = false;
            try {
                $enqueue(new FunctionMessage(
                    project: $project,
                    userId: $data['userId'] ?? '',
                    functionId: $functionId,
                    execution: new Document(['$id' => $execution->getId()]),
                    type: 'schedule',
                    body: $data['body'] ?? '',
                    path: $data['path'] ?? '/',
                    headers: $data['headers'] ?? [],
                    method: $data['method'] ?? 'POST',
                ));
                $published = true;

                if (!$dbForPlatform->deleteDocument('schedules', $scheduleId)) {
                    throw new \RuntimeException('Failed to remove claimed execution schedule');
                }

                return true;
            } catch (\Throwable $error) {
                // Stay inside the lock. A waiting cancel observes this restore
                // and deletes the row; once publish has succeeded the row stays
                // inactive even if cleanup fails.
                if (!$published) {
                    $dbForPlatform->updateDocument('schedules', $scheduleId, new Document([
                        'resourceUpdatedAt' => DateTime::now(),
                        'active' => true,
                    ]));
                }
                throw $error;
            }
        });

        return $enqueued === true;
    }

    /**
     * Delete the schedule when publication has not been committed.
     *
     * False when the row is already claimed (`active` false) or gone.
     * {@see Exception::GENERAL_RESOURCE_LOCKED} when the lock cannot be
     * acquired before publication's outcome is known.
     */
    public function cancel(Database $dbForPlatform, string $scheduleId, ?Authorization $authorization = null): bool
    {
        try {
            $cancelled = $this->hold($scheduleId, function () use ($dbForPlatform, $scheduleId, $authorization): bool {
                $delete = fn (): bool => $dbForPlatform->withTransaction(function () use ($dbForPlatform, $scheduleId): bool {
                    $schedule = $dbForPlatform->getDocument('schedules', $scheduleId, forUpdate: true);

                    if ($schedule->isEmpty() || !$schedule->getAttribute('active', false)) {
                        return false;
                    }

                    return $dbForPlatform->deleteDocument('schedules', $scheduleId);
                });

                if (!$authorization instanceof Authorization) {
                    return $delete();
                }

                return $authorization->skip($delete) === true;
            });
        } catch (Contention $error) {
            throw new Exception(Exception::GENERAL_RESOURCE_LOCKED, previous: $error);
        }

        return $cancelled === true;
    }

    private function hold(string $scheduleId, callable $callback): mixed
    {
        return ($this->locks)(self::key($scheduleId), self::LOCK_TTL, $callback, self::LOCK_TIMEOUT);
    }

    private static function key(string $scheduleId): string
    {
        return 'lock:platform:schedules:' . $scheduleId;
    }
}
