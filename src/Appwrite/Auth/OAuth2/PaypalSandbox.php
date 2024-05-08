<?php

namespace Appwrite\Auth\OAuth2;

class PaypalSandbox extends Paypal
{
    protected string $environment = 'sandbox';

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'paypalSandbox';
    }
}
