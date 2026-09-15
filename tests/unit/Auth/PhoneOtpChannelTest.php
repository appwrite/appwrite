<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Appwrite\Auth\PhoneOtpChannel;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneOtpChannelTest extends TestCase
{
    #[DataProvider('provideSmsConfigurations')]
    public function testIsSmsConfigured(bool $providerConfigured, bool $senderConfigured, bool $expected): void
    {
        $this->assertSame($expected, PhoneOtpChannel::isSmsConfigured($providerConfigured, $senderConfigured));
    }

    /**
     * @return Generator<string, array{bool, bool, bool}>
     */
    public static function provideSmsConfigurations(): Generator
    {
        yield 'neither provider nor sender' => [false, false, false];
        yield 'provider without a sender' => [true, false, false];
        yield 'sender without a provider' => [false, true, false];
        yield 'both provider and sender' => [true, true, true];
    }

    #[DataProvider('provideResolutions')]
    public function testResolve(
        string $policy,
        ?string $requested,
        bool $smsConfigured,
        bool $whatsappConfigured,
        ?string $channel,
        bool $fallback,
    ): void {
        $resolved = PhoneOtpChannel::resolve($policy, $requested, $smsConfigured, $whatsappConfigured);

        $expected = $channel === null ? null : [$channel, $fallback];
        $actual = $resolved === null ? null : [$resolved->channel, $resolved->fallback];

        $this->assertSame($expected, $actual);
    }

    /**
     * @return Generator<string, array{string, string|null, bool, bool, string|null, bool}>
     */
    public static function provideResolutions(): Generator
    {
        yield 'sms policy, no request, no providers' => [PHONE_OTP_CHANNEL_SMS, null, false, false, null, false];
        yield 'sms policy, no request, only whatsapp configured' => [PHONE_OTP_CHANNEL_SMS, null, false, true, null, false];
        yield 'sms policy, no request, only sms configured' => [PHONE_OTP_CHANNEL_SMS, null, true, false, PHONE_OTP_CHANNEL_SMS, false];
        yield 'sms policy, no request, both configured' => [PHONE_OTP_CHANNEL_SMS, null, true, true, PHONE_OTP_CHANNEL_SMS, false];
        yield 'sms policy rejects an sms request, no providers' => [PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_SMS, false, false, null, false];
        yield 'sms policy rejects an sms request, only whatsapp configured' => [PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_SMS, false, true, null, false];
        yield 'sms policy rejects an sms request, only sms configured' => [PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_SMS, true, false, null, false];
        yield 'sms policy rejects an sms request, both configured' => [PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_SMS, true, true, null, false];
        yield 'sms policy rejects a whatsapp request, no providers' => [PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_WHATSAPP, false, false, null, false];
        yield 'sms policy rejects a whatsapp request, only whatsapp configured' => [PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_WHATSAPP, false, true, null, false];
        yield 'sms policy rejects a whatsapp request, only sms configured' => [PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_WHATSAPP, true, false, null, false];
        yield 'sms policy rejects a whatsapp request, both configured' => [PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_WHATSAPP, true, true, null, false];

        yield 'whatsapp policy, no request, no providers' => [PHONE_OTP_CHANNEL_WHATSAPP, null, false, false, null, false];
        yield 'whatsapp policy, no request, only sms configured' => [PHONE_OTP_CHANNEL_WHATSAPP, null, true, false, null, false];
        yield 'whatsapp policy, no request, only whatsapp configured' => [PHONE_OTP_CHANNEL_WHATSAPP, null, false, true, PHONE_OTP_CHANNEL_WHATSAPP, false];
        yield 'whatsapp policy, no request, both configured' => [PHONE_OTP_CHANNEL_WHATSAPP, null, true, true, PHONE_OTP_CHANNEL_WHATSAPP, false];
        yield 'whatsapp policy rejects an sms request, no providers' => [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_SMS, false, false, null, false];
        yield 'whatsapp policy rejects an sms request, only sms configured' => [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_SMS, true, false, null, false];
        yield 'whatsapp policy rejects an sms request, only whatsapp configured' => [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_SMS, false, true, null, false];
        yield 'whatsapp policy rejects an sms request, both configured' => [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_SMS, true, true, null, false];
        yield 'whatsapp policy rejects a whatsapp request, no providers' => [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_WHATSAPP, false, false, null, false];
        yield 'whatsapp policy rejects a whatsapp request, only sms configured' => [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_WHATSAPP, true, false, null, false];
        yield 'whatsapp policy rejects a whatsapp request, only whatsapp configured' => [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_WHATSAPP, false, true, null, false];
        yield 'whatsapp policy rejects a whatsapp request, both configured' => [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_WHATSAPP, true, true, null, false];

        yield 'whatsapp-sms policy, no request, no providers' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, false, false, null, false];
        yield 'whatsapp-sms policy, no request, only sms configured' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, true, false, PHONE_OTP_CHANNEL_SMS, false];
        yield 'whatsapp-sms policy, no request, only whatsapp configured' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, false, true, PHONE_OTP_CHANNEL_WHATSAPP, true];
        yield 'whatsapp-sms policy, no request, both configured' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, true, true, PHONE_OTP_CHANNEL_WHATSAPP, true];
        yield 'whatsapp-sms policy, sms requested, no providers' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_SMS, false, false, null, false];
        yield 'whatsapp-sms policy, sms requested, only whatsapp configured' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_SMS, false, true, null, false];
        yield 'whatsapp-sms policy, sms requested, only sms configured' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_SMS, true, false, PHONE_OTP_CHANNEL_SMS, false];
        yield 'whatsapp-sms policy, sms requested, both configured' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_SMS, true, true, PHONE_OTP_CHANNEL_SMS, false];
        yield 'whatsapp-sms policy, whatsapp requested, no providers' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_WHATSAPP, false, false, null, false];
        yield 'whatsapp-sms policy, whatsapp requested, only sms configured' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_WHATSAPP, true, false, null, false];
        yield 'whatsapp-sms policy, whatsapp requested, only whatsapp configured' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_WHATSAPP, false, true, PHONE_OTP_CHANNEL_WHATSAPP, false];
        yield 'whatsapp-sms policy, whatsapp requested, both configured, no fallback' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_WHATSAPP, true, true, PHONE_OTP_CHANNEL_WHATSAPP, false];
        yield 'whatsapp-sms policy, whatsapp-sms requested, both configured' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_WHATSAPP_SMS, true, true, PHONE_OTP_CHANNEL_WHATSAPP, true];
        yield 'whatsapp-sms policy rejects an unknown request' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, 'telegram', true, true, null, false];

        yield 'unknown policy, no request, both configured' => ['telegram', null, true, true, null, false];
        yield 'unknown policy rejects a request, both configured' => ['telegram', PHONE_OTP_CHANNEL_SMS, true, true, null, false];
    }
}
