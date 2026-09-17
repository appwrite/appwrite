<?php

namespace Appwrite\Auth;

/**
 * The channel a phone OTP is delivered on, resolved from the project policy,
 * the channel the client requested and the providers the instance has configured.
 */
final readonly class PhoneOTPChannel
{
    private function __construct(
        public readonly string $channel,
        public readonly bool $fallback,
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
     * Null means the request cannot be delivered: no provider for the resolved channel,
     * or the client requested a channel under a policy that does not allow choosing.
     * A requested channel narrows the policy, so requesting WhatsApp under whatsapp-sms never falls back.
     */
    public static function resolve(
        string $policy,
        ?string $requested,
        bool $smsConfigured,
        bool $whatsappConfigured,
    ): ?self {
        if ($requested !== null && $policy !== PHONE_OTP_CHANNEL_WHATSAPP_SMS) {
            return null;
        }

        return match ($requested ?? $policy) {
            PHONE_OTP_CHANNEL_SMS => $smsConfigured
                ? new self(PHONE_OTP_CHANNEL_SMS, false)
                : null,
            PHONE_OTP_CHANNEL_WHATSAPP => $whatsappConfigured
                ? new self(PHONE_OTP_CHANNEL_WHATSAPP, false)
                : null,
            PHONE_OTP_CHANNEL_WHATSAPP_SMS => match (true) {
                $whatsappConfigured => new self(PHONE_OTP_CHANNEL_WHATSAPP, true),
                $smsConfigured => new self(PHONE_OTP_CHANNEL_SMS, false),
                default => null,
            },
            default => null,
        };
    }
}
