<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class PoliciesPhoneOtpChannelIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    /**
     * @return array<string>
     */
    private function channels(): array
    {
        return [
            PHONE_OTP_CHANNEL_SMS,
            PHONE_OTP_CHANNEL_WHATSAPP,
            PHONE_OTP_CHANNEL_WHATSAPP_SMS,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function serverHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }

    private function setChannel(string $channel): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/phone-otp-channel', $this->serverHeaders(), [
            'channel' => $channel,
        ]);
    }

    private function getChannelPolicy(): mixed
    {
        return $this->client->call(Client::METHOD_GET, '/project/policies/phone-otp-channel', $this->serverHeaders());
    }

    /**
     * @return array<string, mixed>
     */
    private function findChannelPolicyInList(): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/project/policies', $this->serverHeaders());

        $this->assertSame(200, $response['headers']['status-code']);

        foreach ($response['body']['policies'] as $policy) {
            if ($policy['$id'] === 'phone-otp-channel') {
                return $policy;
            }
        }

        $this->fail('The phone-otp-channel policy is missing from the policy list.');
    }

    public function testPhoneOtpChannelRoundTrip(): void
    {
        // Test for SUCCESS
        foreach ($this->channels() as $channel) {
            $update = $this->setChannel($channel);

            $this->assertSame(200, $update['headers']['status-code']);
            $this->assertSame('phone-otp-channel', $update['body']['$id']);
            $this->assertSame($channel, $update['body']['channel']);

            $single = $this->getChannelPolicy();

            $this->assertSame(200, $single['headers']['status-code']);
            $this->assertSame('phone-otp-channel', $single['body']['$id']);
            $this->assertSame($channel, $single['body']['channel']);

            $listed = $this->findChannelPolicyInList();

            $this->assertSame($channel, $listed['channel']);
            $this->assertSame($single['body'], $listed);
        }

        // Test for FAILURE
        $invalid = $this->setChannel('carrier-pigeon');

        $this->assertSame(400, $invalid['headers']['status-code']);

        $missing = $this->client->call(Client::METHOD_PATCH, '/project/policies/phone-otp-channel', $this->serverHeaders(), []);

        $this->assertSame(400, $missing['headers']['status-code']);

        $unauthenticated = $this->client->call(Client::METHOD_PATCH, '/project/policies/phone-otp-channel', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'channel' => PHONE_OTP_CHANNEL_SMS,
        ]);

        $this->assertSame(401, $unauthenticated['headers']['status-code']);

        // A rejected update must not have moved the stored channel off the last accepted value.
        $unchanged = $this->getChannelPolicy();

        $this->assertSame(200, $unchanged['headers']['status-code']);
        $this->assertSame(PHONE_OTP_CHANNEL_WHATSAPP_SMS, $unchanged['body']['channel']);

        $reset = $this->setChannel(PHONE_OTP_CHANNEL_SMS);

        $this->assertSame(200, $reset['headers']['status-code']);
        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $reset['body']['channel']);

        $final = $this->getChannelPolicy();

        $this->assertSame(200, $final['headers']['status-code']);
        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $final['body']['channel']);
    }
}
