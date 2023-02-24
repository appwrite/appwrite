<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class SourceValidation extends Model
{
    public function __construct()
    {
        $this
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Success status. "success" or "failed".',
                'default' => "failed",
                'example' => "success",
            ])
            ->addRule('message', [
                'type' => self::TYPE_STRING,
                'description' => 'Extra details about the status.',
                'default' => '',
                'example' => 'Source is valid.',
            ])
            ->addRule('errors', [
                'type' => self::TYPE_STRING,
                'description' => 'Missing roles.',
                'default' => '',
                'example' => '[]',
                'array' => true
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
        return 'SourceValidation';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SOURCE_VALIDATION;
    }
}