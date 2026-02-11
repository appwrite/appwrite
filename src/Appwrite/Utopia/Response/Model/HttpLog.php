<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class HttpLog extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Log ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('resource', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource type. Possible values: `project`, `deployment`.',
                'default' => '',
                'example' => 'deployment',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource ID.',
                'default' => '',
                'example' => '5e5ea6g16897e',
            ])
            ->addRule('durationSeconds', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Request duration in seconds.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('requestMethod', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request method.',
                'default' => '',
                'example' => 'GET',
            ])
            ->addRule('requestScheme', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request scheme.',
                'default' => '',
                'example' => 'https',
            ])
            ->addRule('requestHost', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request host.',
                'default' => '',
                'example' => 'example.com',
            ])
            ->addRule('requestPath', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request path.',
                'default' => '',
                'example' => '/articles',
            ])
            ->addRule('requestQuery', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP request query string.',
                'default' => '',
                'example' => 'id=5',
            ])
            ->addRule('requestSizeBytes', [
                'type' => self::TYPE_INTEGER,
                'description' => 'HTTP request size in bytes.',
                'default' => 0,
                'example' => 256,
            ])
            ->addRule('responseStatusCode', [
                'type' => self::TYPE_INTEGER,
                'description' => 'HTTP response status code.',
                'default' => 0,
                'example' => 200,
            ])
            ->addRule('responseSizeBytes', [
                'type' => self::TYPE_INTEGER,
                'description' => 'HTTP response size in bytes.',
                'default' => 0,
                'example' => 1024,
            ]);
    }

    public function getName(): string
    {
        return 'HttpLog';
    }

    public function getType(): string
    {
        return Response::MODEL_HTTP_LOG;
    }
}
