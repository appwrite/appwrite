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
                'type' => 'string',
                'description' => 'Rule ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('type', [
                'type' => 'string',
                'description' => 'Rule type. Possible values: ',
                'example' => 'title',
            ])
            ->addRule('key', [
                'type' => 'string',
                'description' => 'Rule key.',
                'example' => 'title',
            ])
            ->addRule('label', [
                'type' => 'string',
                'description' => 'Rule label.',
                'example' => 'Title',
            ])
            ->addRule('default', [ // TODO should be of mixed types
                'type' => 'string',
                'description' => 'Rule default value.',
                'example' => 'Movie Name',
                'default' => '',
            ])
            ->addRule('array', [
                'type' => 'boolean',
                'description' => 'Is array?',
                'example' => false,
            ])
            ->addRule('required', [
                'type' => 'boolean',
                'description' => 'Is required?',
                'example' => true,
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