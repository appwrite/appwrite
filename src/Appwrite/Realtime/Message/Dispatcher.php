<?php

namespace Appwrite\Realtime\Message;

use Appwrite\Extend\Exception;
use Utopia\DI\Container;
use Utopia\Platform\Action;

class Dispatcher
{
    public const LABEL_MESSAGE_TYPE = 'messageType';
    public const LABEL_PAYLOAD_SHAPE = 'payloadShape';
    public const LABEL_REQUIRES_PROJECT = 'requiresProjectContext';

    public const PAYLOAD_SHAPE_OBJECT = 'object';
    public const PAYLOAD_SHAPE_LIST = 'list';

    private const REQUIRED_PARAM_ERROR_FORMAT = 'Payload is not valid. %s is required';

    /**
     * @var array<string, Action>
     */
    private array $handlers = [];

    public function addHandler(Action $handler): self
    {
        $labels = $handler->getLabels();
        $type = $labels[self::LABEL_MESSAGE_TYPE]
            ?? throw new \LogicException('Realtime message handler is missing the messageType label.');

        $this->handlers[$type] = $handler;
        return $this;
    }

    /**
     * @return array<string, Action>
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * Routes a parsed websocket message to the handler that registered for its `type`,
     * runs param validation + dependency injection, and returns whatever the handler returns.
     * Errors propagate so the caller can render them as websocket error frames.
     *
     * @param Container $container per-message container resolving 'connection', 'project',
     *                             'projectId' and any handler-declared injections.
     * @param array<mixed> $message decoded inbound websocket frame: `['type' => ..., 'data' => ...]`.
     * @return array<string, mixed>|null the handler's response payload (already shaped for the
     *                                   wire), or null when the handler chooses not to reply.
     */
    public function dispatch(Container $container, array $message): ?array
    {
        $type = $message['type'] ?? '';
        if (!\is_string($type) || !isset($this->handlers[$type])) {
            throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Message type is not valid.');
        }

        $handler = $this->handlers[$type];
        $labels = $handler->getLabels();

        $requiresProject = $labels[self::LABEL_REQUIRES_PROJECT] ?? true;
        if ($requiresProject && empty($container->get('projectId'))) {
            throw new Exception(
                Exception::REALTIME_POLICY_VIOLATION,
                'Missing project context. Reconnect to the project first.'
            );
        }

        $shape = $labels[self::LABEL_PAYLOAD_SHAPE] ?? self::PAYLOAD_SHAPE_OBJECT;
        $dataPresent = \array_key_exists('data', $message);
        $data = $dataPresent ? $message['data'] : null;

        $args = $this->resolveArgs($handler, $data, $shape, $container);

        return ($handler->getCallback())(...$args);
    }

    /**
     * Resolves the ordered argument list for the handler callback by walking the action's
     * declared option sequence. Params come from the inbound `data` (for object shape) or
     * the entire data value (for list shape). Injections come from the per-message container.
     *
     * @return array<int, mixed>
     */
    private function resolveArgs(
        Action $handler,
        mixed $data,
        string $shape,
        Container $container,
    ): array {
        $values = [];
        $dataPresent = $data !== null;
        foreach ($handler->getParams() as $key => $param) {
            if ($shape === self::PAYLOAD_SHAPE_LIST) {
                // The whole `data` field is the value of this single param. `present` reflects
                // whether the inbound message actually contained the `data` key.
                $present = $dataPresent;
                $value = $dataPresent ? $data : $param['default'];
            } else {
                $present = \is_array($data) && \array_key_exists($key, $data);
                $value = $present ? $data[$key] : $param['default'];
            }

            if (!$present && !$param['optional']) {
                throw new Exception(
                    Exception::REALTIME_MESSAGE_FORMAT_INVALID,
                    \sprintf(self::REQUIRED_PARAM_ERROR_FORMAT, \ucfirst($key)),
                );
            }

            if ($present && !($param['skipValidation'] ?? false)) {
                $validator = $param['validator'];
                if (\is_callable($validator) && !($validator instanceof \Utopia\Validator)) {
                    $validator = $validator();
                }
                if (!$validator->isValid($value)) {
                    throw new Exception(
                        Exception::REALTIME_MESSAGE_FORMAT_INVALID,
                        \sprintf('%s: %s', $key, $validator->getDescription())
                    );
                }
            }

            $values[$key] = $value;
        }

        $ordered = [];
        foreach ($handler->getOptions() as $optionKey => $option) {
            if (($option['type'] ?? '') === 'param') {
                $name = \substr($optionKey, \strlen('param:'));
                $ordered[] = $values[$name] ?? null;
            } else {
                $ordered[] = $container->get($option['name']);
            }
        }

        return $ordered;
    }
}
