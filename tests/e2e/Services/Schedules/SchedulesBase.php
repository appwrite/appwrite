<?php

namespace Tests\E2E\Services\Schedules;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

trait SchedulesBase
{
    protected function createSchedule(array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);
    }

    protected function getSchedule(string $scheduleId): array
    {
        return $this->client->call(Client::METHOD_GET, '/schedules/' . $scheduleId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    protected function listSchedules(array $params = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);
    }

    protected function createFunction(array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), array_merge([
            'functionId' => ID::unique(),
            'name' => 'Test Schedule Function',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'execute' => ['any'],
        ], $params));
    }
}
