<?php

namespace Appwrite\Alert;

use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * Acknowledging a firing alert: one member says they have it, and everyone who
 * can read the alert sees who and when. Its escalation stops, and goes on from
 * the next step if the acknowledgement is undone. Nothing else changes; the
 * monitor keeps being evaluated and the alert resolves as before, keeping who
 * handled it.
 */
readonly class Acknowledgements
{
    public function __construct(private Database $dbForPlatform)
    {
    }

    /**
     * Acknowledges the alert as `$user`, or undoes that. Asking for what the alert
     * already holds changes nothing, so the first member to acknowledge stays on it.
     * Called with an API key, `$user` is empty and so is who acknowledged. The name
     * is a snapshot, as the alert keeps its monitor's name from when it fired.
     *
     * @return Document the alert as stored
     * @throws Exception when the alert has resolved
     */
    public function set(Document $alert, bool $acknowledged, Document $user): Document
    {
        if ($alert->getAttribute('status') !== State::Firing->value) {
            throw new Exception(Exception::ALERT_RESOLVED);
        }

        if (($alert->getAttribute('acknowledgedAt') !== null) === $acknowledged) {
            // A notification written after the alert was acknowledged catches up.
            $this->updateNotifications($alert, $acknowledged);

            return $alert;
        }

        $changes = new Document($acknowledged ? [
            'acknowledgedAt' => DateTime::now(),
            'acknowledgedBy' => $user->getId(),
            'acknowledgedByName' => (string) $user->getAttribute('name', ''),
        ] : [
            'acknowledgedAt' => null,
            'acknowledgedBy' => '',
            'acknowledgedByName' => '',
        ]);

        $alert = $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument('monitorAlerts', $alert->getId(), $changes));

        $this->updateNotifications($alert, $acknowledged);
        $escalations = new Escalations($this->dbForPlatform);

        return $acknowledged ? $escalations->stop($alert) : $escalations->resume($alert, new \DateTimeImmutable());
    }

    /**
     * Every member's console notification about the alert shows whether it is acknowledged, so none of them acknowledges it again.
     */
    private function updateNotifications(Document $alert, bool $acknowledged): void
    {
        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocuments('notifications', new Document([
            'status' => $acknowledged ? Change::Acknowledged->value : Change::Firing->value,
        ]), [
            Query::equal('subjectType', [RESOURCE_TYPE_ALERTS]),
            Query::equal('subjectId', [$alert->getId()]),
            Query::equal('status', [$acknowledged ? Change::Firing->value : Change::Acknowledged->value]),
        ]));
    }

    /**
     * The event for going from `$before` to `$after`, or null when the acknowledgement did not change.
     */
    public static function change(Document $before, Document $after): ?Change
    {
        $was = $before->getAttribute('acknowledgedAt') !== null;
        $is = $after->getAttribute('acknowledgedAt') !== null;

        return match (true) {
            $was === $is => null,
            $is => Change::Acknowledged,
            default => Change::Unacknowledged,
        };
    }
}
