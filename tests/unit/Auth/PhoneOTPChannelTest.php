<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Appwrite\Auth\PhoneOTPChannel;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneOTPChannelTest extends TestCase
{
    public function testSmsPolicyDeliversOverSms(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_SMS, null, true, true);

        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $resolved?->channel);
        $this->assertFalse($resolved?->fallback);
    }

    public function testWhatsappPolicyDeliversOverWhatsappWithoutFallback(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP, null, true, true);

        $this->assertSame(PHONE_OTP_CHANNEL_WHATSAPP, $resolved?->channel);
        $this->assertFalse($resolved?->fallback);
    }

    public function testWhatsappSmsPolicyDeliversOverWhatsappWithFallback(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, true, true);

        $this->assertSame(PHONE_OTP_CHANNEL_WHATSAPP, $resolved?->channel);
        $this->assertTrue($resolved?->fallback);
    }

    public function testWhatsappSmsPolicyDeliversOverSmsWhenWhatsappIsMissing(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, true, false);

        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $resolved?->channel);
        $this->assertFalse($resolved?->fallback);
    }

    public function testRequestedChannelNarrowsWhatsappSmsPolicyWithoutFallback(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_WHATSAPP, true, true);

        $this->assertSame(PHONE_OTP_CHANNEL_WHATSAPP, $resolved?->channel);
        $this->assertFalse($resolved?->fallback);

        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_SMS, true, true);

        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $resolved?->channel);
    }

    #[DataProvider('provideUndeliverable')]
    public function testUndeliverableRequestsResolveToNull(string $policy, ?string $requested, bool $smsConfigured, bool $whatsappConfigured): void
    {
        $this->assertNull(PhoneOTPChannel::resolve($policy, $requested, $smsConfigured, $whatsappConfigured));
    }

    /**
     * @return Generator<string, array{string, string|null, bool, bool}>
     */
    public static function provideUndeliverable(): Generator
    {
        yield 'sms policy without an sms provider' => [PHONE_OTP_CHANNEL_SMS, null, false, true];
        yield 'whatsapp policy without a whatsapp provider' => [PHONE_OTP_CHANNEL_WHATSAPP, null, true, false];
        yield 'whatsapp-sms policy without any provider' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, false, false];
        yield 'requested channel under a fixed sms policy' => [PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_WHATSAPP, true, true];
        yield 'requested channel under a fixed whatsapp policy' => [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_SMS, true, true];
        yield 'requested channel whose provider is missing' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_WHATSAPP, true, false];
        yield 'unknown requested channel' => [PHONE_OTP_CHANNEL_WHATSAPP_SMS, 'telegram', true, true];
        yield 'unknown policy' => ['telegram', null, true, true];
    }

    public function testFallbackPolicyIsUnsupportedWithoutAnSmsProvider(): void
    {
        $this->assertFalse(PhoneOTPChannel::supports(PHONE_OTP_CHANNEL_WHATSAPP_SMS, false, true));
        $this->assertTrue(PhoneOTPChannel::supports(PHONE_OTP_CHANNEL_WHATSAPP_SMS, true, true));
    }

    public function testWhatsappPolicyIsSupportedWithoutAnSmsProvider(): void
    {
        $this->assertTrue(PhoneOTPChannel::supports(PHONE_OTP_CHANNEL_WHATSAPP, false, true));
        $this->assertFalse(PhoneOTPChannel::supports(PHONE_OTP_CHANNEL_WHATSAPP, true, false));
    }

    public function testSmsIsOnlyConfiguredWithBothProviderAndSender(): void
    {
        $this->assertTrue(PhoneOTPChannel::isSmsConfigured(true, true));
        $this->assertFalse(PhoneOTPChannel::isSmsConfigured(true, false));
        $this->assertFalse(PhoneOTPChannel::isSmsConfigured(false, true));
    }
}
