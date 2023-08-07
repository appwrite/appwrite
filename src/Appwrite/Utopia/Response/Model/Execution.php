<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Helpers\Role;

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
            ->addRule('requestMethod', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request method type.',
                'default' => '',
                'example' => 'GET',
            ])
            ->addRule('requestPath', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request path and query.',
                'default' => '',
                'example' => '/articles?id=5',
            ])
            ->addRule('requestHeaders', [
                'type' => Response::MODEL_HEADERS,
                'description' => 'HTTP response headers as a key-value object. This will return only whitelisted headers. All headers are returned if execution is created as synchronous.',
                'default' => [],
                'example' => [['Content-Type' => 'application/json']],
                'array' => true,
            ])
            ->addRule('responseStatusCode', [
                'type' => self::TYPE_INTEGER,
                'description' => 'HTTP response status code.',
                'default' => 0,
                'example' => 200,
            ])
            ->addRule('responseBody', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP response body. This will return empty unless execution is created as synchronous.',
                'default' => '',
                'example' => 'Developers are awesome.',
                ])
            ->addRule('responseHeaders', [
                'type' => Response::MODEL_HEADERS,
                'description' => 'HTTP response headers as a key-value object. This will return only whitelisted headers. All headers are returned if execution is created as synchronous.',
                'default' => [],
                'example' => [['Content-Type' => 'application/json']],
                'array' => true,
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
