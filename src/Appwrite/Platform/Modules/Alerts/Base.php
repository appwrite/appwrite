<?php

namespace Appwrite\Platform\Modules\Alerts;

use Appwrite\Alert\Rules;
use Appwrite\Alert\Schedules;
use Appwrite\Alert\Status;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;

/**
 * What the monitor and alert routes share: looking them up in the project, and
 * creating and updating a usage monitor against the registry in
 * `app/config/alerts.php`. Each monitor type's routes only declare their params.
 */
class Base extends Action
{
    protected function getMonitor(Database $dbForPlatform, Authorization $authorization, Document $project, string $monitorId): Document
    {
        $monitor = $authorization->skip(fn () => $dbForPlatform->getDocument('monitors', $monitorId));

        if ($monitor->isEmpty() || $monitor->getAttribute('projectInternalId') !== (string) $project->getSequence()) {
            throw new Exception(Exception::MONITOR_NOT_FOUND);
        }

        return $monitor;
    }

    protected function getAlert(Database $dbForPlatform, Authorization $authorization, Document $project, string $alertId): Document
    {
        $alert = $authorization->skip(fn () => $dbForPlatform->getDocument('monitorAlerts', $alertId));

        if ($alert->isEmpty() || $alert->getAttribute('projectInternalId') !== (string) $project->getSequence()) {
            throw new Exception(Exception::ALERT_NOT_FOUND);
        }

        return $alert;
    }

    /**
     * @param array<string> $rules
     * @param array<string> $notify
     */
    protected function createMonitor(
        string $type,
        string $monitorId,
        string $name,
        array $rules,
        int $window,
        int $cooldown,
        bool $enabled,
        array $notify,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
        $parsed = $this->parseRules($rules);

        [$resourceId, $resourceInternalId] = $this->resolveResource($parsed, $project, $dbForProject, $authorization);

        $monitorId = $monitorId === 'unique()' ? Id::unique() : $monitorId;

        $monitor = new Document([
            '$id' => $monitorId,
            '$permissions' => [],
            'name' => $name,
            'teamId' => (string) $project->getAttribute('teamId', ''),
            'teamInternalId' => (string) $project->getAttribute('teamInternalId', ''),
            'projectId' => $project->getId(),
            'projectInternalId' => (string) $project->getSequence(),
            'region' => (string) $project->getAttribute('region', ''),
            'type' => $type,
            ...$this->ruleAttributes($parsed, $resourceId, $resourceInternalId),
            'window' => $window,
            'cooldown' => $cooldown,
            'notify' => \array_values(\array_unique($notify)),
            'enabled' => $enabled,
            'status' => Status::Ok->value,
            'search' => $this->search($monitorId, $name, $parsed->metric, $parsed->resourceType, $resourceId),
        ]);

        try {
            $monitor = $authorization->skip(fn () => $dbForPlatform->createDocument('monitors', $monitor));
        } catch (DuplicateException) {
            throw new Exception(Exception::MONITOR_ALREADY_EXISTS);
        }

        (new Schedules($dbForPlatform))->write($monitor);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($monitor, Response::MODEL_MONITOR);
    }

    /**
     * Changes only what is passed; the monitor's state and its open alert are kept.
     *
     * @param array<string>|null $rules
     * @param array<string>|null $notify
     */
    protected function updateMonitor(
        string $type,
        string $monitorId,
        ?string $name,
        ?array $rules,
        ?int $window,
        ?int $cooldown,
        ?bool $enabled,
        ?array $notify,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
        $monitor = $this->getMonitor($dbForPlatform, $authorization, $project, $monitorId);

        if ($monitor->getAttribute('type') !== $type) {
            throw new Exception(Exception::MONITOR_TYPE_MISMATCH, "Monitor '{$monitorId}' is a {$monitor->getAttribute('type')} monitor, not a {$type} monitor.");
        }

        $changes = new Document();

        if ($rules !== null) {
            $parsed = $this->parseRules($rules);
            [$resourceId, $resourceInternalId] = $this->resolveResource($parsed, $project, $dbForProject, $authorization);
            foreach ($this->ruleAttributes($parsed, $resourceId, $resourceInternalId) as $key => $value) {
                $changes->setAttribute($key, $value);
            }
        }

        foreach ([
            'name' => $name,
            'window' => $window,
            'cooldown' => $cooldown,
            'enabled' => $enabled,
            'notify' => $notify === null ? null : \array_values(\array_unique($notify)),
        ] as $key => $value) {
            if ($value !== null) {
                $changes->setAttribute($key, $value);
            }
        }

        if (!$changes->isEmpty()) {
            $changes->setAttribute('search', $this->search(
                $monitor->getId(),
                (string) $changes->getAttribute('name', $monitor->getAttribute('name')),
                (string) $changes->getAttribute('metric', $monitor->getAttribute('metric')),
                (string) $changes->getAttribute('resourceType', $monitor->getAttribute('resourceType')),
                (string) $changes->getAttribute('resourceId', $monitor->getAttribute('resourceId')),
            ));

            $monitor = $authorization->skip(fn () => $dbForPlatform->updateDocument('monitors', $monitor->getId(), $changes));
            (new Schedules($dbForPlatform))->write($monitor);
        }

        $response->dynamic($monitor, Response::MODEL_MONITOR);
    }

    /**
     * @param array<string> $rules
     */
    private function parseRules(array $rules): Rules
    {
        try {
            return Rules::parse($rules, Config::getParam('alerts', []));
        } catch (\InvalidArgumentException $e) {
            throw new Exception(Exception::MONITOR_RULES_INVALID, $e->getMessage());
        }
    }

    /**
     * The resource's ID and the internal ID it is stored under. A resource type
     * with no collection is the project itself.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveResource(
        Rules $rules,
        Document $project,
        Database $dbForProject,
        Authorization $authorization,
    ): array {
        $resourceType = $rules->resourceType;
        $resourceId = $rules->resourceId;
        $resource = Config::getParam('alerts', [])['resources'][$resourceType] ?? [];

        if (($resource['project'] ?? true) === false) {
            throw new Exception(Exception::MONITOR_RULES_INVALID, "Resource type '{$resourceType}' cannot be watched by a project monitor.");
        }

        $collection = $resource['collection'] ?? null;

        if ($collection === null) {
            return [$project->getId(), (string) $project->getSequence()];
        }

        if ($resourceId === '') {
            throw new Exception(Exception::MONITOR_RULES_INVALID, "A rule on 'resourceId' is required for resource type '{$resourceType}'.");
        }

        $resource = $authorization->skip(fn () => $dbForProject->getDocument($collection, $resourceId));

        if ($resource->isEmpty()) {
            throw new Exception(Exception::MONITOR_RESOURCE_NOT_FOUND, "Resource '{$resourceId}' of type '{$resourceType}' could not be found.");
        }

        return [$resource->getId(), (string) $resource->getSequence()];
    }

    /**
     * The attributes a monitor stores from its rules, which the worker, the
     * schedules and the list filters read.
     *
     * @return array<string, mixed>
     */
    private function ruleAttributes(Rules $rules, string $resourceId, string $resourceInternalId): array
    {
        return [
            'rules' => $rules->rules,
            'metric' => $rules->metric,
            'comparator' => $rules->comparator->value,
            'threshold' => $rules->threshold,
            'resourceType' => $rules->resourceType,
            'resourceId' => $resourceId,
            'resourceInternalId' => $resourceInternalId,
        ];
    }

    private function search(string $monitorId, string $name, string $metric, string $resourceType, string $resourceId): string
    {
        return \implode(' ', \array_filter([$monitorId, $name, $metric, $resourceType, $resourceId], fn (string $part) => $part !== ''));
    }
}
