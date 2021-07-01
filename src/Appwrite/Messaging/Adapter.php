<?php

namespace Appwrite\Messaging;

abstract class Adapter
{
    public abstract function subscribe(string $project, mixed $identifier, array $roles, array $channels): void;
    public abstract function unsubscribe(mixed $identifier): void;
    public static abstract function send(string $projectId, array $payload, string $event, array $channels, array $permissions, array $options): void;
}
