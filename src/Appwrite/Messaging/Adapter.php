<?php

namespace Appwrite\Messaging;

abstract class Adapter
{
    public abstract function subscribe(string $projectId, mixed $identifier, array $roles, array $channels): void;
    public abstract function unsubscribe(mixed $identifier): void;
    public static abstract function send(string $projectId, array $payload, string $event, array $channels, array $roles, array $options): void;
}
