<?php

namespace Appwrite\Functions;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class EventProcessor
{
    /**
     * Get function events for a project, using Redis cache
     * @param Document|null $project
     * @param Database $dbForProject
     * @return array<string, bool>
     */
    public function getFunctionsEvents(?Document $project, Database $dbForProject): array
    {
        if ($project === null ||
            $project->isEmpty() ||
            $project->getId() === 'console') {
            return [];
        }

        $hostname = $dbForProject->getAdapter()->getHostname();
        $cacheKey = \sprintf(
            '%s-cache-%s:%s:%s:project:%s:functions:events',
            $dbForProject->getCacheName(),
            $hostname ?? '',
            $dbForProject->getNamespace(),
            $dbForProject->getTenant(),
            $project->getId()
        );

        $ttl = 3600; // 1 hour cache TTL
        $cachedFunctionEvents = $dbForProject->getCache()->load($cacheKey, $ttl);

        if ($cachedFunctionEvents !== false) {
            return \json_decode($cachedFunctionEvents, true) ?? [];
        }

        $events = [];
        $limit = 100;
        $sum = 100;
        $offset = 0;

        while ($sum >= $limit) {
            $functions = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->find('functions', [
                Query::select('$id'),
                Query::select('events'),
                Query::limit($limit),
                Query::offset($offset),
                Query::orderAsc('$sequence'),
            ]));

            $sum = \count($functions);
            $offset = $offset + $limit;

            foreach ($functions as $function) {
                $functionEvents = $function->getAttribute('events', []);
                if (!empty($functionEvents)) {
                    \array_push($events, ...$functionEvents);
                }
            }
        }

        $uniqueEvents = \array_flip(\array_unique($events));
        $dbForProject->getCache()->save($cacheKey, \json_encode($uniqueEvents));

        return $uniqueEvents;
    }

    /**
     * Get webhook events for a project from the project's webhooks attribute
     * @param Document|null $project
     * @return array<string, bool>
     */
    public function getWebhooksEvents(?Document $project): array
    {
        if ($project === null || $project->isEmpty() || $project->getId() === 'console') {
            return [];
        }

        $webhooks = $project->getAttribute('webhooks', []);
        if (empty($webhooks)) {
            return [];
        }

        $events = [];
        foreach ($webhooks as $webhook) {
            if ($webhook->getAttribute('enabled', false) !== true) {
                continue;
            }

            $webhookEvents = $webhook->getAttribute('events', []);
            if (!empty($webhookEvents)) {
                \array_push($events, ...$webhookEvents);
            }
        }

        return \array_flip(\array_unique($events));
    }
}
