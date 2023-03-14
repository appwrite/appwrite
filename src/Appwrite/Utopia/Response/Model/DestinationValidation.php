<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DestinationValidation extends Model
{
    public function __construct()
    {
        $this
            ->addRule('success', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'If the validation was successful or not.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('message', [
                'type' => self::TYPE_STRING,
                'description' => 'Validation message.',
                'default' => '',
                'example' => 'Validation completed successfully',
            ])
            ->addRule('errors', [
                'type' => Response::MODEL_TRANSFER_VALIDATION_ERROR,
                'description' => 'A key-value array of all the validation errors.',
                'default' => [],
                'example' => [
                    'Users' => ['Access to table "public.users" is denied.'],
                    'Databases' => ['Failed to access database. Please check your connection.'],
                ],
                'requried' => false
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
        return 'DestinationValidation';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DESTINATION_VALIDATION;
    }
}
