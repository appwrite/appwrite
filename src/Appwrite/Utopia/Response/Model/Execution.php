<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Execution extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Execution ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('functionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'example' => '5e5ea6g16897e',
            ])
            ->addRule('dateCreated', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The execution creation date in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('trigger', [
                'type' => self::TYPE_STRING,
                'description' => 'The trigger that caused the function to execute. Possible values can be: `http`, `schedule`, or `event`.',
                'example' => 'http',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'The status of the function execution. Possible values can be: `waiting`, `processing`, `completed`, or `failed`.',
                'example' => 'processing',
            ])
            ->addRule('exitCode', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The script exit code.',
                'example' => 0,
            ])
            ->addRule('stdout', [
                'type' => self::TYPE_STRING,
                'description' => 'The script stdout output string.',
                'example' => '',
            ])
            ->addRule('stderr', [
                'type' => self::TYPE_STRING,
                'description' => 'The script stderr output string.',
                'example' => '',
            ])
            ->addRule('time', [
                'type' => self::TYPE_FLOAT,
                'description' => 'The script execution time in seconds.',
                'example' => 0.400,
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
        return 'Execution';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_EXECUTION;
    }
}