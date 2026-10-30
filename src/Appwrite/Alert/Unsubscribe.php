<?php

namespace Appwrite\Alert;

use Ahc\Jwt\JWT;

/**
 * The signed token an alert email's unsubscribe link and header carry: whose
 * emails stop, and the monitor that sent them. It expires, and is refused when
 * altered, signed with another key or issued for something else.
 */
readonly class Unsubscribe
{
    /**
     * How a user's opt-out from every alert email of a monitor type is stored,
     * next to the IDs of single monitors. Monitor IDs hold no colon, so it
     * cannot be mistaken for one.
     */
    public static function type(string $type): string
    {
        return 'type:' . $type;
    }

    private const string PURPOSE = 'alerts_unsubscribe';

    public function __construct(
        private string $secret,
        private int $ttl = ALERT_UNSUBSCRIBE_TTL,
    ) {
    }

    /**
     * @param ?int $now Unix time the token is issued at; the current time when null
     */
    public function token(string $userId, string $monitorId, ?int $now = null): string
    {
        return $this->jwt($now)->encode([
            'userId' => $userId,
            'monitorId' => $monitorId,
            'purpose' => self::PURPOSE,
        ]);
    }

    /**
     * @param ?int $now Unix time the token is checked at; the current time when null
     * @return array{userId: string, monitorId: string}
     * @throws \InvalidArgumentException when the token is not a valid unsubscribe token
     */
    public function verify(string $token, ?int $now = null): array
    {
        try {
            $claims = $this->jwt($now)->decode($token);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Invalid unsubscribe token.', previous: $error);
        }

        if (
            ($claims['purpose'] ?? null) !== self::PURPOSE
            || !\is_string($claims['userId'] ?? null) || $claims['userId'] === ''
            || !\is_string($claims['monitorId'] ?? null) || $claims['monitorId'] === ''
        ) {
            throw new \InvalidArgumentException('Invalid unsubscribe token.');
        }

        return ['userId' => $claims['userId'], 'monitorId' => $claims['monitorId']];
    }

    private function jwt(?int $now): JWT
    {
        return (new JWT($this->secret, 'HS256', $this->ttl, 0))->setTestTimestamp($now);
    }
}
