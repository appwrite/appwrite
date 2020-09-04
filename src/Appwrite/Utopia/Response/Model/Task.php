<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Task extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => 'string',
                'description' => 'Task ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'Task name.',
                'example' => 'My Task',
            ])
            ->addRule('security', [
                'type' => 'boolean',
                'description' => 'Indicated if SSL / TLS Certificate verification is enabled.',
                'example' => true,
            ])
            ->addRule('httpMethod', [
                'type' => 'string',
                'description' => 'Task HTTP Method.',
                'example' => 'POST',
            ])
            ->addRule('httpUrl', [
                'type' => 'string',
                'description' => 'Task HTTP URL.',
                'example' => 'https://example.com/task',
            ])
            ->addRule('httpHeaders', [
                'type' => 'string',
                'description' => 'Task HTTP headers.',
                'default' => [],
                'example' => ['key:value'],
                'array' => true,
            ])
            ->addRule('httpUser', [
                'type' => 'string',
                'description' => 'HTTP basic authentication username.',
                'default' => '',
                'example' => 'username',
            ])
            ->addRule('httpPass', [
                'type' => 'string',
                'description' => 'HTTP basic authentication password.',
                'default' => '',
                'example' => 'password',
            ])
            ->addRule('duration', [
                'type' => 'float',
                'description' => 'Task duration in seconds.',
                'default' => 0,
                'example' => 1.2,
            ])
            ->addRule('delay', [
                'type' => 'float',
                'description' => 'Task delay time in seconds.',
                'default' => 0,
                'example' => 1.2,
            ])
            ->addRule('failures', [
                'type' => 'integer',
                'description' => 'Number of recurring task failures.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('schedule', [
                'type' => 'string',
                'description' => 'Task schedule in CRON syntax.',
                'example' => '* * * * *',
            ])
            ->addRule('status', [
                'type' => 'string',
                'description' => 'Task status. Possible values: play, pause', // TODO - change to enabled disabled
                'example' => 'enabled',
            ])
            ->addRule('updated', [
                'type' => 'integer',
                'description' => 'Task last updated time in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('previous', [
                'type' => 'integer',
                'description' => 'Task previous run time in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('next', [
                'type' => 'integer',
                'description' => 'Task next run time in Unix timestamp.',
                'default' => 0,
                'example' => 1592981650,
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Task';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_TASK;
    }
}