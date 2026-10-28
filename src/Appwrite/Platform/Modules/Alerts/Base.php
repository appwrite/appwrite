<?php

namespace Appwrite\Platform\Modules\Alerts;

use Appwrite\Alert\Channel;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

/**
 * What creating and updating an alert rule share: the rule lookup, the checks
 * against the metric registry in `app/config/alerts.php`, and resolving the
 * watched resource in the project.
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
        $collection = Config::getParam('alerts', [])['resources'][$resourceType]['collection'] ?? null;

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

    protected function search(string $ruleId, string $name, string $metric, string $resourceType, string $resourceId): string
    {
        return \implode(' ', \array_filter([$ruleId, $name, $metric, $resourceType, $resourceId], fn (string $part) => $part !== ''));
    }
}
