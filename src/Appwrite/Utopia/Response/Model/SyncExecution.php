<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class SyncExecution extends Model
{
    public function __construct()
    {
        $this
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Execution Status.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('exitCode', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Execution Exit Code.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('stdout', [
                'type' => self::TYPE_STRING,
                'description' => 'Execution Stdout.',
                'default' => '',
                'example' => 'Hello World!',
            ])
            ->addRule('stderr', [
                'type' => self::TYPE_STRING,
                'description' => 'Execution Stderr.',
                'default' => '',
                'example' => 'An error occoured: ....... example',
            ])
            ->addRule('time', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Execution Time.',
                'default' => 0,
                'example' => 100,
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
        return 'Syncronous Execution';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_SYNC_EXECUTION;
    }
}