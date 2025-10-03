<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PaymentFeature extends Model
{
    public function __construct()
    {
        $this
            ->addRule('featureId', [
                'type' => self::TYPE_STRING,
                'description' => 'Feature ID.',
                'default' => '',
                'example' => 'seats',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Feature name.',
                'default' => '',
                'example' => 'Seats',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Feature type (boolean, metered).',
                'default' => 'boolean',
                'example' => 'metered',
            ])
            ->addRule('description', [
                'type' => self::TYPE_STRING,
                'description' => 'Feature description.',
                'default' => '',
                'example' => 'Number of seat licenses',
            ])
            ->addRule('providers', [
                'type' => self::TYPE_JSON,
                'description' => 'Provider-specific metadata.',
                'default' => new \stdClass(),
                'example' => new \stdClass(),
            ]);
    }

    public function getName(): string
    {
        return 'PaymentFeature';
    }

    public function getType(): string
    {
        return Response::MODEL_PAYMENT_FEATURE;
    }
}


