<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PaymentProviderConfig extends Model
{
    public function __construct()
    {
        $this
            ->addRule('providers', [
                'type' => self::TYPE_JSON,
                'description' => 'Payment providers configuration',
                'default' => [],
                'example' => ['stripe' => ['enabled' => true]]
            ])
            ->addRule('defaults', [
                'type' => self::TYPE_JSON,
                'description' => 'Default payment settings',
                'default' => [],
                'example' => []
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether payments feature is enabled',
                'default' => true,
                'example' => true
            ]);
    }

    public function getName(): string
    {
        return 'PaymentProviderConfig';
    }

    public function getType(): string
    {
        return Response::MODEL_PAYMENT_PROVIDER_CONFIG;
    }
}
