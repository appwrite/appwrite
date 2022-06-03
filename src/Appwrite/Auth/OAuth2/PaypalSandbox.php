<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Paypal;

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
