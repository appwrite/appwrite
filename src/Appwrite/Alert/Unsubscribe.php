<?php

namespace Appwrite\Alert;

use Ahc\Jwt\JWT;

/**
 * The signed token an alert email's unsubscribe links carry: whose emails
 * stop, and for which monitor or for every monitor of a type (`type()`). It
 * expires, and is refused when altered, signed with another key or issued for
 * something else.
 */
readonly class Unsubscribe
{
    /**
     * The scope that stops every alert email of a monitor type. Monitor IDs
     * hold no colon, so it cannot be mistaken for one.
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
    public function token(string $userId, string $scope, ?int $now = null): string
    {
        return $this->jwt($now)->encode([
            'userId' => $userId,
            'scope' => $scope,
            'purpose' => self::PURPOSE,
        ]);
    }

    /**
     * @param ?int $now Unix time the token is checked at; the current time when null
     * @return array{userId: string, scope: string}
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
            || !\is_string($claims['scope'] ?? null) || $claims['scope'] === ''
        ) {
            throw new \InvalidArgumentException('Invalid unsubscribe token.');
        }

        return ['userId' => $claims['userId'], 'scope' => $claims['scope']];
    }

    private function jwt(?int $now): JWT
    {
        return (new JWT($this->secret, 'HS256', $this->ttl, 0))->setTestTimestamp($now);
    }
}
