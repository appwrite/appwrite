<?php

namespace Appwrite\Event;

use Exception;
use InvalidArgumentException;
use Resque;

class Event
{
    const DATABASE_QUEUE_NAME = 'v1-database';
    const DATABASE_CLASS_NAME = 'DatabaseV1';

    const DELETE_QUEUE_NAME = 'v1-deletes';
    const DELETE_CLASS_NAME = 'DeletesV1';

    const AUDITS_QUEUE_NAME = 'v1-audits';
    const AUDITS_CLASS_NAME = 'AuditsV1';

    const USAGE_QUEUE_NAME = 'v1-usage';
    const USAGE_CLASS_NAME = 'UsageV1';

    const MAILS_QUEUE_NAME = 'v1-mails';
    const MAILS_CLASS_NAME = 'MailsV1';

    const FUNCTIONS_QUEUE_NAME = 'v1-functions';
    const FUNCTIONS_CLASS_NAME = 'FunctionsV1';

    const WEBHOOK_QUEUE_NAME = 'v1-webhooks';
    const WEBHOOK_CLASS_NAME = 'WebhooksV1';

    const CERTIFICATES_QUEUE_NAME = 'v1-certificates';
    const CERTIFICATES_CLASS_NAME = 'CertificatesV1';

    const BUILDS_QUEUE_NAME = 'v1-builds';
    const BUILDS_CLASS_NAME = 'BuildsV1';

    protected string $queue = '';
    protected string $class = '';
    protected array $params = [];

    /**
     * @param string $queue
     * @param string $class
     * @return void
     */
    public function __construct(string $queue, string $class)
    {
        $this->queue = $queue;
        $this->class = $class;
    }

    /**
     * Set queue used for this event.
     *
     * @param string $queue
     * @return Event
     */
    public function setQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Get queue used for this event.
     *
     * @return string
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Set class used for this event.
     * @param string $class
     * @return Event
     */
    public function setClass(string $class): self
    {
        $this->class = $class;
        return $this;
    }

    /**
     * Get class used for this event.
     *
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * Set param of event.
     *
     * @param string $key
     * @param mixed $value
     * @return Event
     */
    public function setParam(string $key, mixed $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Get param of event.
     *
     * @param string $key
     * @return mixed
     */
    public function getParam(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    /**
     * Execute Event.
     *
     * @return Event
     * @throws InvalidArgumentException
     */
    public function trigger(): self
    {
        Resque::enqueue($this->queue, $this->class, $this->params);

        return $this->reset();
    }

    /**
     * Resets event.
     *
     * @return Event 
     */
    public function reset(): self
    {
        $this->params = [];

        return $this;
    }

    static function generateEvents(string $pattern, array $params = []): array
    {
        $parts = \explode('.', $pattern);
        $count = \count($parts);

        if ($count < 2 || $count > 6) {
            throw new Exception("Patten incorrect.");
        }

        /**
         * Identify all sestions of the pattern.
         */
        $type = $parts[0];
        $action = match ($count) {
            2 => $parts[1],
            3, 4 => $parts[2],
            5, 6 => $parts[4]
        };

        if ($count > 4) {
            $subType = $parts[2];
            $subResource = $parts[3];
            if ($count === 6) {
                $attribute = $parts[5];
            }
        }
        if ($count > 2) {
            $resource = $parts[1];
            if ($count === 4) {
                $attribute = $parts[3];
            }
        }

        $paramKeys = \array_keys($params);
        $paramValues = \array_values($params);

        $patterns = [];
        $resource ??= false;
        $subResource ??= false;
        $attribute ??= false;

        if (empty($params) && ($type ?? false) && !$resource) {
            return [$pattern];
        }

        if ($resource && !\in_array(\trim($resource, '[]'), $paramKeys)) {
            throw new InvalidArgumentException("{$resource} is missing from the params.");
        }

        if ($subResource && !\in_array(\trim($subResource, '[]'), $paramKeys)) {
            throw new InvalidArgumentException("{$subResource} is missing from the params.");
        }

        /**
         * Create all possible patterns including placeholders.
         */
        if ($action) {
            if ($subResource) {
                if ($attribute) {
                    $patterns[] = \implode('.', [$type, $resource, $subType, $subResource, $action, $attribute]);
                }
                $patterns[] = \implode('.', [$type, $resource, $subType, $subResource, $action]);
                $patterns[] = \implode('.', [$type, $resource, $subType, $subResource]);
            } else {
                if ($attribute) {
                    $patterns[] = \implode('.', [$type, $resource, $action, $attribute]);
                }
                $patterns[] = \implode('.', [$type, $resource, $action]);
                $patterns[] = \implode('.', [$type, $resource]);
            }
        }
        if ($subResource) {
            $patterns[] = \implode('.', [$type, $resource, $subType, $subResource]);
        }

        /**
         * Removes all duplicates.
         */
        $patterns = \array_unique($patterns);

        /**
         * Set all possible values of the patterns and replace placeholders.
         */
        $events = [];
        foreach ($patterns as $eventPattern) {
            $events[] = \str_replace($paramKeys, $paramValues, $eventPattern);
            $events[] = \str_replace($paramKeys, '*', $eventPattern);
            foreach ($paramKeys as $key) {
                foreach ($paramKeys as $current) {
                    if ($current === $key) continue;

                    $filtered = \array_filter($paramKeys, fn(string $k) => $k === $current);
                    $events[] = \str_replace($paramKeys, $paramValues, \str_replace($filtered, '*', $eventPattern));
                }
            }
        }

        /**
         * Remove [] from the events.
         */
        $events = \array_map(fn (string $event) => \str_replace(['[', ']'], '', $event), $events);

        return $events;
    }
}
