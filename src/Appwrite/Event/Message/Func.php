<?php

namespace Appwrite\Event\Message;

use Appwrite\Event\Event;
use Utopia\Config\Config;
use Utopia\Database\Document;

final class Func extends Base
{
    public function __construct(
        public readonly ?Document $project = null,
        public readonly ?Document $user = null,
        public readonly ?string $userId = null,
        public readonly ?Document $function = null,
        public readonly ?string $functionId = null,
        public readonly ?Document $execution = null,
        public readonly string $type = '',
        public readonly string $jwt = '',
        public readonly array $payload = [],
        public readonly array $events = [],
        public readonly string $body = '',
        public readonly string $path = '',
        public readonly array $headers = [],
        public readonly string $method = '',
        public readonly array $platform = [],
    ) {
    }

    public static function fromEvent(
        string $event,
        array $params,
        ?Document $project = null,
        ?Document $user = null,
        ?string $userId = null,
        array $payload = [],
        array $platform = [],
    ): static {
        return new self(
            project: $project,
            user: $user,
            userId: $userId,
            payload: $payload,
            events: $event !== '' ? Event::generateEvents($event, $params) : [],
            platform: $platform,
        );
    }

    public function toArray(): array
    {
        $platform = !empty($this->platform) ? $this->platform : Config::getParam('platform', []);

        return [
            'project' => $this->project?->getArrayCopy(),
            'user' => $this->user?->getArrayCopy(),
            'userId' => $this->userId,
            'function' => $this->function?->getArrayCopy(),
            'functionId' => $this->functionId,
            'execution' => $this->execution?->getArrayCopy(),
            'type' => $this->type,
            'jwt' => $this->jwt,
            'payload' => $this->payload,
            'events' => $this->events,
            'body' => $this->body,
            'path' => $this->path,
            'headers' => $this->headers,
            'method' => $this->method,
            'platform' => $platform,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: !empty($data['project']) ? new Document($data['project']) : null,
            user: !empty($data['user']) ? new Document($data['user']) : null,
            userId: $data['userId'] ?? null,
            function: !empty($data['function']) ? new Document($data['function']) : null,
            functionId: $data['functionId'] ?? null,
            execution: !empty($data['execution']) ? new Document($data['execution']) : null,
            type: $data['type'] ?? '',
            jwt: $data['jwt'] ?? '',
            payload: $data['payload'] ?? [],
            events: $data['events'] ?? [],
            body: $data['body'] ?? '',
            path: $data['path'] ?? '',
            headers: $data['headers'] ?? [],
            method: $data['method'] ?? '',
            platform: $data['platform'] ?? [],
        );
    }
}
