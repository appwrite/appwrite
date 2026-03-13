<?php

namespace Appwrite\Messaging;

abstract class Adapter
{
    abstract public function subscribe(string $projectId, mixed $identifier, string $subscriptionId, array $roles, array $channels, array $queryGroup = []): void;
    abstract public function unsubscribe(mixed $identifier): void;
    abstract public function send(string $projectId, array $payload, array $events, array $channels, array $roles, array $options): void;
}
