<?php

namespace Appwrite\Alert;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;

/**
 * An alert's escalation: which step of its escalation is notified next, and when.
 * The time is kept on the alert (`escalatesAt`) and in the alert's row in
 * `schedules`, which the alerts scheduler fires once when it is due. Moving
 * `resourceUpdatedAt` re-arms that row, so every write touches it.
 */
readonly class Escalations
{
    /** Seconds until a step the scheduler failed to enqueue is tried again. */
    private const int RETRY = 10;

    public function __construct(private Database $dbForPlatform)
    {
    }

    /**
     * The organization's escalation, or an empty document when it is gone or
     * belongs to another organization, as it does once a project is transferred.
     */
    public function escalation(string $escalationId, string $teamInternalId): Document
    {
        if ($escalationId === '') {
            return new Document();
        }

        $escalation = $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->getDocument('escalations', $escalationId));

        return $escalation->getAttribute('teamInternalId') === $teamInternalId ? $escalation : new Document();
    }

    /**
     * Starts escalating a firing alert through the escalation, whose steps and
     * repeats the alert keeps: the first step is due its delay from now. An
     * alert already escalating is left as it is, so a retried evaluation goes
     * on from where the first one got to.
     */
    public function start(Document $alert, Document $escalation, \DateTimeImmutable $now): Document
    {
        if ($alert->getAttribute('escalationId', '') !== '') {
            return $alert;
        }

        $parsed = Escalation::fromDocument($escalation);
        $steps = $parsed->steps;
        if ($steps === []) {
            return $alert;
        }

        return $this->write($alert, [
            'escalationId' => $escalation->getId(),
            'escalationSteps' => $parsed->toArray(),
            'escalationRepeat' => $parsed->repeat,
            'escalationStep' => 0,
            'escalationRound' => 0,
            'escalatesAt' => $alert->getAttribute('acknowledgedAt') === null ? self::format($now->modify('+' . $steps[0]->delay . ' seconds')) : null,
        ]);
    }

    /**
     * Moves on once the alert's current step has been notified: to the next
     * step, or to the first again for another round, or to nothing once the
     * last round is done, leaving `escalationStep` past the last step.
     *
     * @param list<string> $userIds members notified by this step
     */
    public function advance(Document $alert, Escalation $escalation, int $cooldown, \DateTimeImmutable $now, array $userIds): Document
    {
        $step = (int) $alert->getAttribute('escalationStep', 0);
        $round = (int) $alert->getAttribute('escalationRound', 0);
        $next = $escalation->after($step, $round, $cooldown);

        $changes = $next === null ? [
            'escalationStep' => \count($escalation->steps),
            'escalatesAt' => null,
        ] : [
            'escalationStep' => $next['step'],
            'escalationRound' => $next['round'],
            'escalatesAt' => self::format($now->modify('+' . $next['delay'] . ' seconds')),
        ];
        $changes['escalationUserIds'] = \array_values(\array_unique([...$alert->getAttribute('escalationUserIds', []), ...$userIds]));

        return $this->write($alert, $changes);
    }

    /**
     * Stops the escalation, as acknowledging or resolving the alert does. The
     * step it reached is kept, so a resumed escalation goes on from it.
     */
    public function stop(Document $alert): Document
    {
        if ($alert->getAttribute('escalationId', '') === '') {
            return $alert;
        }

        return $this->write($alert, ['escalatesAt' => null]);
    }

    /**
     * Goes on with an escalation that was stopped while the alert still fires,
     * as undoing an acknowledgement does: the next step is due its delay from
     * now. An escalation that already notified every step stays done.
     */
    public function resume(Document $alert, \DateTimeImmutable $now): Document
    {
        if ($alert->getAttribute('escalationId', '') === '' || $alert->getAttribute('escalatesAt') !== null) {
            return $alert;
        }

        $step = Escalation::fromAlert($alert)->steps[(int) $alert->getAttribute('escalationStep', 0)] ?? null;
        if ($step === null) {
            return $alert;
        }

        return $this->write($alert, ['escalatesAt' => self::format($now->modify('+' . $step->delay . ' seconds'))]);
    }

    /**
     * Fires the alert's timer again a few seconds from now, for a due step the
     * scheduler could not hand to the worker. The alert keeps its due time, so
     * the worker finds the step due.
     */
    public function retry(string $alertId): void
    {
        $schedule = $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->findOne('schedules', [
            Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
            Query::equal('resourceType', [SCHEDULE_RESOURCE_TYPE_ESCALATION]),
            Query::equal('resourceId', [$alertId]),
        ]));
        if ($schedule->isEmpty() || !$schedule->getAttribute('active', false)) {
            return;
        }

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
            'schedule' => self::format(new \DateTimeImmutable('+' . self::RETRY . ' seconds')),
            'resourceUpdatedAt' => DateTime::now(),
        ])));
    }

    /**
     * Re-arms the alert's schedule for the time it already holds, for a timer
     * that fired before the alert was due.
     */
    public function arm(Document $alert): void
    {
        $this->schedule($alert, $alert->getAttribute('escalatesAt'));
    }

    /**
     * Stops the schedule of an alert that is gone, with the project it was in.
     */
    public function forget(string $alertId): void
    {
        $schedule = $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->findOne('schedules', [
            Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
            Query::equal('resourceType', [SCHEDULE_RESOURCE_TYPE_ESCALATION]),
            Query::equal('resourceId', [$alertId]),
        ]));
        if ($schedule->isEmpty() || !$schedule->getAttribute('active', false)) {
            return;
        }

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
            'active' => false,
            'resourceUpdatedAt' => DateTime::now(),
        ])));
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function write(Document $alert, array $changes): Document
    {
        $alert = $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument('monitorAlerts', $alert->getId(), new Document($changes)));
        $this->schedule($alert, $alert->getAttribute('escalatesAt'));

        return $alert;
    }

    /**
     * Creates or refreshes the alert's row in `schedules`: due at `$at`, or inactive without one.
     */
    private function schedule(Document $alert, ?string $at): void
    {
        $schedule = $this->find($alert->getId(), (string) $alert->getAttribute('region'), (string) $alert->getAttribute('projectId', ''));

        if ($schedule->isEmpty() && $at === null) {
            return;
        }

        $changes = [
            'active' => $at !== null,
            'schedule' => $at ?? '',
            'resourceUpdatedAt' => DateTime::now(),
        ];

        $this->dbForPlatform->getAuthorization()->skip(fn () => $schedule->isEmpty()
            ? $this->dbForPlatform->createDocument('schedules', new Document([
                'region' => (string) $alert->getAttribute('region'),
                'resourceType' => SCHEDULE_RESOURCE_TYPE_ESCALATION,
                'resourceId' => $alert->getId(),
                'resourceInternalId' => (string) $alert->getSequence(),
                'projectId' => (string) $alert->getAttribute('projectId', ''),
                'projectInternalId' => $alert->getAttribute('projectInternalId') ?: null,
                ...$changes,
            ]))
            : $this->dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document($changes)));
    }

    private function find(string $alertId, string $region, string $projectId): Document
    {
        return $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->findOne('schedules', [
            Query::equal('region', [$region]),
            Query::equal('resourceType', [SCHEDULE_RESOURCE_TYPE_ESCALATION]),
            Query::equal('projectId', [$projectId]),
            Query::equal('resourceId', [$alertId]),
        ]));
    }

    private static function format(\DateTimeImmutable $moment): string
    {
        return DateTime::format(\DateTime::createFromImmutable($moment));
    }
}
