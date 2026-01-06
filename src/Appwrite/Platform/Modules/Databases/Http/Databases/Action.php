<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\Query;

class Action extends AppwriteAction
{
    private string $context = 'legacy';

    public function getDatabaseType(): string
    {
        return $this->context;
    }

    public function setHttpPath(string $path): AppwriteAction
    {
        if (\str_contains($path, '/tablesdb')) {
            $this->context = 'tablesdb';
        }
        return parent::setHttpPath($path);
    }

    /**
     * Parse operator strings in data array and convert them to Operator objects.
     *
     * @param array $data The data array that may contain operator JSON strings or arrays
     * @param Document $collection The collection document to check for relationship attributes
     * @return array The data array with operators converted to Operator objects
     * @throws Exception If an operator string is invalid
     */
    protected function parseOperators(array $data, Document $collection): array
    {
        $relationshipKeys = [];
        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            if ($attribute->getAttribute('type') === Database::VAR_RELATIONSHIP) {
                $relationshipKeys[$attribute->getAttribute('key')] = true;
            }
        }

        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                if (\is_array($value)) {
                    $data[$key] = $this->parseOperators($value, $collection);
                }
                continue;
            }

            if (\str_starts_with($key, '$')) {
                continue;
            }

            if (isset($relationshipKeys[$key])) {
                continue;
            }

            // Handle operator as JSON string (from API requests)
            if (\is_string($value)) {
                $decoded = \json_decode($value, true);

                if (
                    \is_array($decoded) &&
                    isset($decoded['method']) &&
                    \is_string($decoded['method']) &&
                    Operator::isMethod($decoded['method'])
                ) {
                    try {
                        $data[$key] = Operator::parse($value);
                    } catch (\Exception $e) {
                        throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid operator for attribute "' . $key . '": ' . $e->getMessage());
                    }
                }
            }
            // Handle operator as array (from transaction logs after serialization)
            elseif (
                \is_array($value) &&
                isset($value['method']) &&
                \is_string($value['method']) &&
                Operator::isMethod($value['method'])
            ) {
                try {
                    $data[$key] = Operator::parseOperator($value);
                } catch (\Exception $e) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid operator for attribute "' . $key . '": ' . $e->getMessage());
                }
            } elseif (\is_array($value)) {
                $data[$key] = $this->parseOperators($value, $collection);
            }
        }

        return $data;
    }

    /**
     * Get function events for a project, using Redis cache
     * @param Document|null $project
     * @param Database $dbForProject
     * @return array<string, bool>
     */
    protected function getFunctionsEvents(?Document $project, Database $dbForProject): array
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

        try {
            $events = [];
            $limit = 100;
            $sum = 100;
            $offset = 0;

            while ($sum >= $limit) {
                $functions = $dbForProject->find('functions', [
                    Query::select(['$id', 'events']),
                    Query::limit($limit),
                    Query::offset($offset),
                    Query::orderAsc('$sequence'),
                ]);

                $sum = \count($functions);
                $offset = $offset + $limit;

                foreach ($functions as $function) {
                    $functionEvents = $function->getAttribute('events', []);
                    if (!empty($functionEvents)) {
                        $events = array_merge($events, $functionEvents);
                    }
                }
            }

            $uniqueEvents = \array_flip(\array_unique($events));
            $dbForProject->getCache()->save($cacheKey, \json_encode($uniqueEvents));

            return $uniqueEvents;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get webhook events for a project from the project's webhooks attribute
     * @param Document|null $project
     * @return array<string, bool>
     */
    protected function getWebhooksEvents(?Document $project): array
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
                $events = array_merge($events, $webhookEvents);
            }
        }

        return \array_flip(\array_unique($events));
    }
}
