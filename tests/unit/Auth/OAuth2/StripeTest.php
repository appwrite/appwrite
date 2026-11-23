<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Exception;
use Appwrite\Auth\OAuth2\Stripe;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class StripeTest extends TestCase
{
    private const TOKEN_URL = 'https://connect.stripe.com/oauth/token';
    private const ACCOUNT_URL = 'https://api.stripe.com/v1/accounts/acct_1MwGfvGUcADgqoBE';

    public function testIdentityIsTheConnectedAccountTheGrantIsFor(): void
    {
        $stripe = new FakeStripe([
            self::TOKEN_URL => [
                'access_token' => 'sk_test_access-token',
                'stripe_user_id' => 'acct_1MwGfvGUcADgqoBE',
            ],
            self::ACCOUNT_URL => [
                'id' => 'acct_1MwGfvGUcADgqoBE',
                'email' => 'owner@example.com',
                'name' => 'Example Store',
            ],
        ]);

        $accessToken = $stripe->getAccessToken('authorization-code');

        // The identity is the connected account, not the administrator who authorized the grant
        $this->assertSame('acct_1MwGfvGUcADgqoBE', $stripe->getUserID($accessToken));
        $this->assertSame('Example Store', $stripe->getUserName($accessToken));
        $this->assertSame('owner@example.com', $stripe->getUserEmail($accessToken));
        $this->assertFalse($stripe->isEmailVerified($accessToken));
    }

    public function testAccountIdentityIsLinkOnly(): void
    {
        // Every administrator of the connected account gets the same ID, so it must never sign anyone in
        $this->assertTrue((new FakeStripe([]))->isLinkOnly());
    }
}

final class FakeStripe extends Stripe
{
    /** @var array<string, array<string>> */
    public array $headers = [];

    /** @var array<string, string> */
    public array $payloads = [];

    /**
     * @param array<string, array<string, mixed>> $responses JSON body per URL; any other URL answers 403
     */
    public function __construct(private readonly array $responses)
    {
        parent::__construct(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');
    }

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $this->headers[$url] = $headers;
        $this->payloads[$url] = $payload;

        if (!isset($this->responses[$url])) {
            throw new Exception('{"error":{"type":"invalid_request_error","message":"No such account."}}', 403);
        }

        return \json_encode($this->responses[$url], JSON_THROW_ON_ERROR);
    }
}
