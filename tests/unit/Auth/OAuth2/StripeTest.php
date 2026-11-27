<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Stripe;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class StripeTest extends TestCase
{
    public function testConnectedAccountIdentityIsLinkOnly(): void
    {
        // Every administrator of the connected account gets the same ID, so it must never sign anyone in
        $stripe = new Stripe(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');

        $this->assertTrue($stripe->isLinkOnly());
    }
}
