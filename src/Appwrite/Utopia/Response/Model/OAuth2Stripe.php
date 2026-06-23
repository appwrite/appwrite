<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Stripe extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'stripe',
    ];

    public function getProviderLabel(): string
    {
        return 'Stripe';
    }

    public function getClientIdExample(): string
    {
        return 'ca_UKibXX0000000000000000000006byvR';
    }

    public function getClientSecretExample(): string
    {
        return 'sk_51SfOd000000000000000000000000000000000000000000000000000000000000000000000000000000000000000QGWYfp';
    }

    public function getClientSecretFieldName(): string
    {
        return 'apiSecretKey';
    }

    public function getClientSecretLabel(): string
    {
        return 'API secret key';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Stripe';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_STRIPE;
    }
}
