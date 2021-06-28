<?php

namespace Appwrite\Messaging;

abstract class Adapter
{
    public abstract function subscribe(string $project, mixed $identifier, array $roles, array $channels): void;
    public abstract function unsubscribe(mixed $identifier): void;
    public abstract function send(string $projectId, string $event, array $payload): void;
}
