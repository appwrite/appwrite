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

        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $resolved->channel);
        $this->assertFalse($resolved->fallback);
    }

    public function testWhatsappPolicyDeliversOverWhatsappWithoutFallback(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP, null, true, true);

        $this->assertSame(PHONE_OTP_CHANNEL_WHATSAPP, $resolved->channel);
        $this->assertFalse($resolved->fallback);
    }

    public function testWhatsappSmsPolicyDeliversOverWhatsappWithFallback(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, true, true);

        $this->assertSame(PHONE_OTP_CHANNEL_WHATSAPP, $resolved->channel);
        $this->assertTrue($resolved->fallback);
    }

    public function testWhatsappSmsPolicyDeliversOverSmsWhenWhatsappIsMissing(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, true, false);

        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $resolved->channel);
        $this->assertFalse($resolved->fallback);
    }

    public function testRequestedChannelNarrowsWhatsappSmsPolicyWithoutFallback(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_WHATSAPP, true, true);

        $this->assertSame(PHONE_OTP_CHANNEL_WHATSAPP, $resolved->channel);
        $this->assertFalse($resolved->fallback);

        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP_SMS, PHONE_OTP_CHANNEL_SMS, true, true);

        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $resolved->channel);
    }

    #[DataProvider('provideUndeliverable')]
    public function testUndeliverableRequestsResolveToNull(string $policy, ?string $requested, bool $smsConfigured, bool $whatsappConfigured): void
    {
        $this->assertNotInstanceOf(PhoneOTPChannel::class, PhoneOTPChannel::resolve($policy, $requested, $smsConfigured, $whatsappConfigured));
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

    public function testUnreachableNumberFallsBackToSmsWithoutTryingWhatsapp(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP_SMS, null, true, true, false);

        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $resolved->channel);
        $this->assertFalse($resolved->fallback);
    }

    public function testUnreachableNumberCannotBeServedByAWhatsappOnlyPolicy(): void
    {
        $this->assertNotInstanceOf(PhoneOTPChannel::class, PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_WHATSAPP, null, true, true, false));
    }

    public function testUnreachableNumberDoesNotAffectTheSmsPolicy(): void
    {
        $resolved = PhoneOTPChannel::resolve(PHONE_OTP_CHANNEL_SMS, null, true, true, false);

        $this->assertSame(PHONE_OTP_CHANNEL_SMS, $resolved->channel);
    }

    /**
     * @return Generator<string, array{?string, string, bool}>
     */
    public static function callingCodes(): Generator
    {
        yield 'denied on its own' => ['91', '91', true];
        yield 'denied among others' => ['91', '1,91,44', true];
        yield 'denied with spacing' => ['91', '1, 91 , 44', true];
        yield 'allowed' => ['1', '91', false];
        yield 'not a prefix match' => ['9', '91', false];
        yield 'longer code is not truncated' => ['911', '91', false];
        yield 'unknown calling code' => [null, '91', false];
        yield 'empty calling code' => ['', '91', false];
        yield 'empty deny list' => ['91', '', false];
    }

    #[DataProvider('callingCodes')]
    public function testCallingCodesMetaRefusesAreDenied(?string $callingCode, string $denied, bool $expected): void
    {
        $this->assertSame($expected, PhoneOTPChannel::isCallingCodeDenied($callingCode, $denied));
    }

    public function testReachabilityIsRememberedUnderAHashRatherThanTheNumber(): void
    {
        $key = PhoneOTPChannel::unreachableKey('+14155550142');

        $this->assertStringNotContainsString('4155550142', $key);
        $this->assertSame($key, PhoneOTPChannel::unreachableKey('+14155550142'));
        $this->assertNotSame($key, PhoneOTPChannel::unreachableKey('+14155550143'));
    }
}
