<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Task extends Model
{
    /**
     * @var bool
     */
    protected $public = false;

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Task ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Task name.',
                'default' => '',
                'example' => 'My Task',
            ])
            ->addRule('security', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Indicated if SSL / TLS Certificate verification is enabled.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('httpMethod', [
                'type' => self::TYPE_STRING,
                'description' => 'Task HTTP Method.',
                'default' => '',
                'example' => 'POST',
            ])
            ->addRule('httpUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'Task HTTP URL.',
                'default' => '',
                'example' => 'https://example.com/task',
            ])
            ->addRule('httpHeaders', [
                'type' => self::TYPE_STRING,
                'description' => 'Task HTTP headers.',
                'default' => [],
                'example' => 'key:value',
                'array' => true,
            ])
            ->addRule('httpUser', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP basic authentication username.',
                'default' => '',
                'example' => 'username',
            ])
            ->addRule('httpPass', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP basic authentication password.',
                'default' => '',
                'example' => 'password',
            ])
            ->addRule('duration', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Task duration in seconds.',
                'default' => 0,
                'example' => 1.2,
            ])
            ->addRule('delay', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Task delay time in seconds.',
                'default' => 0,
                'example' => 1.2,
            ])
            ->addRule('failures', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of recurring task failures.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('schedule', [
                'type' => self::TYPE_STRING,
                'description' => 'Task schedule in CRON syntax.',
                'default' => '',
                'example' => '* * * * *',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Task status. Possible values: play, pause', // TODO - change to enabled disabled
                'default' => '',
                'example' => 'enabled',
            ])
            ->addRule('updated', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Task last updated time in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('previous', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Task previous run time in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('next', [
                'type' => self::TYPE_INTEGER,
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
