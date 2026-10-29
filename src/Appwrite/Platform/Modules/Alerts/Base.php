<?php

namespace Appwrite\Platform\Modules\Alerts;

use Appwrite\Alert\Channel;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * What creating and updating an alert rule share: the rule lookup, the checks
 * against the metric registry in `app/config/alerts.php`, resolving the
 * watched resource in the project, and the rule's row in `schedules`.
 */
class Base extends Action
{
    protected function getRule(Database $dbForPlatform, Authorization $authorization, Document $project, string $ruleId): Document
    {
        $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('alertRules', $ruleId));

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== (string) $project->getSequence()) {
            throw new Exception(Exception::ALERT_RULE_NOT_FOUND);
        }

        return $rule;
    }

    protected function assertMetric(string $metric, string $resourceType): void
    {
        $resources = Config::getParam('alerts', [])['metrics'][$metric]['resources'] ?? [];

        if (!\in_array($resourceType, $resources, true)) {
            throw new Exception(Exception::ALERT_RULE_INVALID_METRIC, "Metric '{$metric}' is not available for resource type '{$resourceType}'.");
        }
    }

    /**
     * @param array<string> $channels
     */
    protected function assertWebhook(array $channels, string $webhookUrl): void
    {
        if (\in_array(Channel::Webhook->value, $channels, true) && $webhookUrl === '') {
            throw new Exception(Exception::ALERT_RULE_WEBHOOK_URL_MISSING);
        }
    }

    /**
     * The resource's ID and the internal ID it is stored under. A resource type
     * with no collection is the project itself.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveResource(
        string $resourceType,
        string $resourceId,
        Document $project,
        Database $dbForProject,
        Authorization $authorization,
    ): array {
        $resource = Config::getParam('alerts', [])['resources'][$resourceType] ?? [];

        if (($resource['project'] ?? true) === false) {
            throw new Exception(Exception::ALERT_RULE_INVALID_RESOURCE, "Resource type '{$resourceType}' cannot be watched by a project alert rule.");
        }

        $collection = $resource['collection'] ?? null;

        if ($collection === null) {
            return [$project->getId(), (string) $project->getSequence()];
        }

        if ($resourceId === '') {
            throw new Exception(Exception::ALERT_RULE_INVALID_RESOURCE, "A resource ID is required for resource type '{$resourceType}'.");
        }

        $resource = $authorization->skip(fn () => $dbForProject->getDocument($collection, $resourceId));

        if ($resource->isEmpty()) {
            throw new Exception(Exception::ALERT_RULE_INVALID_RESOURCE, "Resource '{$resourceId}' of type '{$resourceType}' could not be found.");
        }

        return [$resource->getId(), (string) $resource->getSequence()];
    }

    /**
     * Writes the rule's evaluation schedule: active while the rule is enabled.
     * Moving `resourceUpdatedAt` is what the scheduler syncs on, so every write
     * of the rule touches it.
     */
    protected function schedule(Database $dbForPlatform, Document $rule): void
    {
        $schedule = $this->findSchedule($dbForPlatform, $rule);
        $changes = [
            'active' => (bool) $rule->getAttribute('enabled', false),
            'resourceUpdatedAt' => DateTime::now(),
        ];

        $dbForPlatform->getAuthorization()->skip(fn () => $schedule->isEmpty()
            ? $dbForPlatform->createDocument('schedules', new Document([
                'region' => (string) $rule->getAttribute('region'),
                'resourceType' => SCHEDULE_RESOURCE_TYPE_ALERT,
                'resourceId' => $rule->getId(),
                'resourceInternalId' => (string) $rule->getSequence(),
                'projectId' => (string) $rule->getAttribute('projectId', ''),
                'projectInternalId' => (string) $rule->getAttribute('projectInternalId', ''),
                'schedule' => '',
                'data' => ['teamInternalId' => (string) $rule->getAttribute('teamInternalId')],
                ...$changes,
            ]))
            : $dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document($changes)));
    }

    /**
     * Stops a deleted rule's schedule; the schedules sweep removes the row
     * once the rule is gone.
     */
    protected function unschedule(Database $dbForPlatform, Document $rule): void
    {
        $schedule = $this->findSchedule($dbForPlatform, $rule);
        if ($schedule->isEmpty()) {
            return;
        }

        $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
            'active' => false,
            'resourceUpdatedAt' => DateTime::now(),
        ])));
    }

    private function findSchedule(Database $dbForPlatform, Document $rule): Document
    {
        return $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->findOne('schedules', [
            Query::equal('region', [(string) $rule->getAttribute('region')]),
            Query::equal('resourceType', [SCHEDULE_RESOURCE_TYPE_ALERT]),
            Query::equal('projectInternalId', [(string) $rule->getAttribute('projectInternalId', '')]),
            Query::equal('resourceId', [$rule->getId()]),
        ]));
    }

    protected function search(string $ruleId, string $name, string $metric, string $resourceType, string $resourceId): string
    {
        return \implode(' ', \array_filter([$ruleId, $name, $metric, $resourceType, $resourceId], fn (string $part) => $part !== ''));
    }
}
