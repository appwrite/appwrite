<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Role;

class Execution extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Execution ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Execution creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Execution upate date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Execution roles.',
                'default' => '',
                'example' => [Role::any()->toString()],
                'array' => true,
            ])
            ->addRule('functionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'default' => '',
                'example' => '5e5ea6g16897e',
            ])
            ->addRule('trigger', [
                'type' => self::TYPE_STRING,
                'description' => 'The trigger that caused the function to execute. Possible values can be: `http`, `schedule`, or `event`.',
                'default' => '',
                'example' => 'http',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'The status of the function execution. Possible values can be: `waiting`, `processing`, `completed`, or `failed`.',
                'default' => '',
                'example' => 'processing',
            ])
            ->addRule('agent', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request user agent header.',
                'default' => '',
                'example' => 'Chrome/51.0.2704.103',
            ])
            ->addRule('method', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request method type.',
                'default' => '',
                'example' => 'GET',
            ])
            ->addRule('path', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request path and query.',
                'default' => '',
                'example' => '/articles?id=5',
            ])
            ->addRule('statusCode', [
                'type' => self::TYPE_INTEGER,
                'description' => 'HTTP response status code.',
                'default' => 0,
                'example' => 200,
            ])
            ->addRule('logs', [
                'type' => self::TYPE_STRING,
                'description' => 'Function logs. Includes the last 4,000 characters. This will return an empty string unless the response is returned using an API key or as part of a webhook payload.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('errors', [
                'type' => self::TYPE_STRING,
                'description' => 'Function errors. Includes the last 4,000 characters. This will return an empty string unless the response is returned using an API key or as part of a webhook payload.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('duration', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Function execution duration in seconds.',
                'default' => 0,
                'example' => 0.400,
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Execution';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_EXECUTION;
    }
}
