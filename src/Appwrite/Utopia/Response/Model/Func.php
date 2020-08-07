<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Func extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => 'string',
                'description' => 'Function ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'Function name.',
                'example' => 'My Function',
            ])
            ->addRule('dateCreated', [
                'type' => 'integer',
                'description' => 'Function creation date in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('dateUpdated', [
                'type' => 'integer',
                'description' => 'Function update date in Unix timestamp.',
                'example' => 1592981257,
            ])
            ->addRule('status', [
                'type' => 'string',
                'description' => 'Function status. Possible values: disabled, enabled',
                'example' => 'enabled',
            ])
            ->addRule('env', [
                'type' => 'string',
                'description' => 'Function execution environment.',
                'example' => 'python-3.8',
            ])
            ->addRule('tag', [
                'type' => 'string',
                'description' => 'Function active tag ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('vars', [
                'type' => 'json',
                'description' => 'Function environment variables.',
                'default' => new \stdClass,
                'example' => ['key' => 'value'],
            ])
            ->addRule('events', [
                'type' => 'string',
                'description' => 'Function trigger events.',
                'default' => '',
                'example' => 'account.create',
                'array' => true,
            ])
            ->addRule('schedule', [
                'type' => 'string',
                'description' => 'Function execution schedult in CRON format.',
                'default' => '',
                'example' => '5 4 * * *',
            ])
            ->addRule('next', [
                'type' => 'integer',
                'description' => 'Function next scheduled execution date in Unix timestamp.',
                'example' => 1592981292,
            ])
            ->addRule('previous', [
                'type' => 'integer',
                'description' => 'Function next scheduled execution date in Unix timestamp.',
                'example' => 1592981237,
            ])
            ->addRule('timeout', [
                'type' => 'integer',
                'description' => 'Function execution timeout in seconds.',
                'default' => 15,
                'example' => 1592981237,
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
        return 'Function';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_FUNCTION;
    }
}