<?php

namespace Appwrite\Auth;

use Utopia\Cache\Cache;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;

/**
 * The channel a phone OTP is delivered on, resolved from the project policy,
 * the channel the client requested and the providers the instance has configured.
 */
final readonly class PhoneOTPChannel
{
    private function __construct(
        public string $channel,
        public bool $fallback,
    ) {
    }

    /**
     * The worker only builds its SMS adapter when both the provider DSN and the sender are set.
     */
    public static function isSmsConfigured(bool $providerConfigured, bool $senderConfigured): bool
    {
        return $providerConfigured && $senderConfigured;
    }

    /**
     * Whether the instance has every provider a policy relies on, so a project
     * cannot opt into a channel, or a fallback, that could never deliver.
     */
    public static function supports(string $policy, bool $smsConfigured, bool $whatsappConfigured): bool
    {
        return match ($policy) {
            PHONE_OTP_CHANNEL_SMS => $smsConfigured,
            PHONE_OTP_CHANNEL_WHATSAPP => $whatsappConfigured,
            PHONE_OTP_CHANNEL_WHATSAPP_SMS => $whatsappConfigured && $smsConfigured,
            default => false,
        };
    }

    /**
     * Whether Meta refuses authentication templates addressed to this calling code, in which
     * case WhatsApp would answer with 131026 however healthy the configuration is.
     */
    public static function isCallingCodeDenied(?string $callingCode, string $denied): bool
    {
        if ($callingCode === null || $callingCode === '') {
            return false;
        }

        foreach (\explode(',', $denied) as $code) {
            if (\trim($code) === $callingCode) {
                return true;
            }
        }

        return false;
    }

    /**
     * The key a number's WhatsApp reachability is remembered under. Hashed, because a cache
     * entry should not be a readable list of the phone numbers an instance has sent to.
     */
    public static function unreachableKey(string $phone): string
    {
        return \hash('sha256', $phone);
    }

    /**
     * Whether WhatsApp can be expected to reach this number at all: Meta must allow
     * authentication templates to its calling code, and it must not already have reported
     * the number undeliverable.
     */
    public static function isDeliverableOverWhatsApp(string $phone, string $denied, Cache $cache): bool
    {
        if (self::isCallingCodeDenied(CallingCode::fromPhoneNumber($phone), $denied)) {
            return false;
        }

        $remembered = $cache->load(
            PHONE_OTP_WHATSAPP_UNREACHABLE_KEY . ':' . self::unreachableKey($phone),
            PHONE_OTP_WHATSAPP_UNREACHABLE_TTL,
        );

        return empty($remembered);
    }

    /**
     * Null means the request cannot be delivered: no provider for the resolved channel,
     * or the client requested a channel under a policy that does not allow choosing.
     * A requested channel narrows the policy, so requesting WhatsApp under whatsapp-sms never falls back.
     *
     * $whatsappDeliverable is false once this recipient is known to be beyond WhatsApp's reach,
     * either because Meta denies the calling code or because it already reported the number
     * undeliverable. Under whatsapp-sms that sends the code straight over SMS rather than
     * spending a doomed attempt and the seconds it takes Meta to report the failure.
     */
    public static function resolve(
        string $policy,
        ?string $requested,
        bool $smsConfigured,
        bool $whatsappConfigured,
        bool $whatsappDeliverable = true,
    ): ?self {
        if ($requested !== null && $policy !== PHONE_OTP_CHANNEL_WHATSAPP_SMS) {
            return null;
        }

        $whatsapp = $whatsappConfigured && $whatsappDeliverable;

        return match ($requested ?? $policy) {
            PHONE_OTP_CHANNEL_SMS => $smsConfigured
                ? new self(PHONE_OTP_CHANNEL_SMS, false)
                : null,
            PHONE_OTP_CHANNEL_WHATSAPP => $whatsapp
                ? new self(PHONE_OTP_CHANNEL_WHATSAPP, false)
                : null,
            PHONE_OTP_CHANNEL_WHATSAPP_SMS => match (true) {
                $whatsapp => new self(PHONE_OTP_CHANNEL_WHATSAPP, true),
                $smsConfigured => new self(PHONE_OTP_CHANNEL_SMS, false),
                default => null,
            },
            default => null,
        };
    }
}
