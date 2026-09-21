<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Paypal extends OAuth2Base
{
    public array $conditions = [
        '$id' => ['paypal', 'paypalSandbox'],
    ];

    public function getProviderLabel(): string
    {
        return 'PayPal';
    }

    public function getClientIdExample(): string
    {
        return 'AdhIEG7-000000000000-0000000000000000000000000000000-0000000000000000000000-2pyB';
    }

    public function getClientSecretExample(): string
    {
        return 'EH8KCXtew--000000000000000000000000000000000000000_C-1_5UP_000000000000000CB7KDp';
    }

    public function getClientSecretFieldName(): string
    {
        return 'secretKey';
    }

    public function getClientSecretLabel(): string
    {
        return 'secret key';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Paypal';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_PAYPAL;
    }
}
