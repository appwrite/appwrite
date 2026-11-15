<?php

namespace Appwrite\Alert;

use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

/**
 * Acknowledging a firing alert: one member says they have it, and everyone who
 * can read the alert sees who and when. It changes nothing else; the monitor
 * keeps being evaluated and the alert resolves as before, keeping who handled it.
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

        return $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument('monitorAlerts', $alert->getId(), $changes));
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
