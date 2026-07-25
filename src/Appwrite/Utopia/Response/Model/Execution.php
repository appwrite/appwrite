<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
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
                'description' => 'Execution update date in ISO 8601 format.',
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
            // TODO: Sites listLogs will not have this, and will need siteId instead
            ->addRule('functionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'default' => '',
                'example' => '5e5ea6g16897e',
            ])
            ->addRule('deploymentId', [
                'type' => self::TYPE_STRING,
                'description' => 'Function\'s deployment ID used to create the execution.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('trigger', [
                'type' => self::TYPE_ENUM,
                'description' => 'The trigger that caused the function to execute. Possible values can be: `http`, `schedule`, or `event`.',
                'default' => '',
                'example' => 'http',
                'enum' => ['http', 'schedule', 'event'],
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'The status of the function execution. Possible values can be: `waiting`, `processing`, `completed`, `failed`, or `scheduled`.',
                'default' => '',
                'example' => 'processing',
                'enum' => ['waiting', 'processing', 'completed', 'failed', 'scheduled'],
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
                'description' => 'HTTP request headers as a key-value object. This will return only whitelisted headers. All headers are returned if execution is created as synchronous.',
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
                'sensitive' => true,
            ])
            ->addRule('errors', [
                'type' => self::TYPE_STRING,
                'description' => 'Function errors. Includes the last 4,000 characters. This will return an empty string unless the response is returned using an API key or as part of a webhook payload.',
                'default' => '',
                'example' => '',
                'sensitive' => true,
            ])
            ->addRule('duration', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Resource(function/site) execution duration in seconds.',
                'default' => 0,
                'example' => 0.400,
            ])
            ->addRule('scheduledAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'The scheduled time for execution. If left empty, execution will be queued immediately.',
                'required' => false,
                'default' => DateTime::now(),
                'example' => self::TYPE_DATETIME_EXAMPLE,
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


    /**
     * Convert DB structure to response model
     *
     * @return Document
     */
    public function filter(Document $document): Document
    {
        $document->removeAttribute('resourceType');
        $document->setAttribute('functionId', $document->getAttribute('resourceId', ''));
        $document->removeAttribute('resourceId');
        return $document;
    }
}
