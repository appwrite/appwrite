<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Rule extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Rule ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$collection', [ // TODO remove this from public response
                'type' => self::TYPE_STRING,
                'description' => 'Rule Collection.',
                'example' => '5e5e66c16897e',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Rule type. Possible values: ',
                'example' => 'title',
            ])
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Rule key.',
                'example' => 'title',
            ])
            ->addRule('label', [
                'type' => self::TYPE_STRING,
                'description' => 'Rule label.',
                'example' => 'Title',
            ])
            ->addRule('default', [ // TODO should be of mixed types
                'type' => self::TYPE_STRING,
                'description' => 'Rule default value.',
                'example' => 'Movie Name',
                'default' => '',
            ])
            ->addRule('array', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is array?',
                'example' => false,
            ])
            ->addRule('required', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is required?',
                'example' => true,
            ])
            ->addRule('list', [
                'type' => self::TYPE_STRING,
                'description' => 'List of allowed values',
                'array' => true,
                'default' => [],
                'example' => ['5e5ea5c168099'],
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
        return 'Rule';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_RULE;
    }
}