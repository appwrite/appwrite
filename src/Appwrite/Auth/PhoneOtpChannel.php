<?php

namespace Appwrite\Auth;

/**
 * The channel a phone OTP is actually delivered on, once the project policy,
 * the channel the client asked for and the providers the instance configured
 * have all been taken into account.
 *
 * The channel values themselves live in app/init/constants.php as
 * PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_WHATSAPP and
 * PHONE_OTP_CHANNEL_WHATSAPP_SMS. This class holds only the decision.
 */
final readonly class PhoneOtpChannel
{
    private function __construct(
        public readonly string $channel,
        public readonly bool $fallback,
    ) {
    }

    /**
     * Resolve the channel to deliver on, or null when the request cannot be
     * delivered at all — either because the instance has no provider for the
     * channel the policy resolved to, or because the client asked for a channel
     * under a policy that does not let it choose.
     *
     * Null on its own does not say which of the two happened, so callers tell
     * them apart from the arguments they already hold: a non-null requested
     * channel under a policy other than whatsapp-sms is the client's mistake
     * (Exception::GENERAL_ARGUMENT_INVALID), a null with no provider configured
     * at all is Exception::GENERAL_PHONE_DISABLED, and a null with at least one
     * provider configured is Exception::PROJECT_PHONE_OTP_CHANNEL_UNAVAILABLE.
     *
     * A client-requested channel narrows the policy, so requesting WhatsApp
     * under the whatsapp-sms policy delivers on WhatsApp without falling back
     * to SMS — falling back would ignore what the client asked for.
     */
    /**
     * Whether the instance can actually deliver over SMS.
     *
     * The worker builds its internal SMS adapter only when both the provider DSN and the
     * sender are set, so a caller that treats the DSN alone as "SMS is configured" resolves
     * whatsapp-sms to a fallback the worker then silently declines to take. Both facts are
     * read by the caller and passed in: this class never touches the environment.
     */
    public static function isSmsConfigured(bool $providerConfigured, bool $senderConfigured): bool
    {
        return $providerConfigured && $senderConfigured;
    }

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
