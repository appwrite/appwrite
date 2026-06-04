<?php

namespace Appwrite\Push;

use Utopia\Messaging\Helpers\JWT;

/**
 * Token issuer + verifier for Appwrite Push device sessions.
 *
 * Devices receive a short-lived JWT signed with the project HMAC secret. The
 * broker validates the same signature on CONNECT to bind the connection to a
 * specific topic. Token claims:
 *
 *   scope  - "device" for end-user devices, "server" for Appwrite workers
 *   sub    - clientId (deviceId for devices, server identifier for workers)
 *   topic  - the topic the device is allowed to subscribe to
 *   exp    - unix timestamp the token stops being honored
 *   iat    - issued-at, mostly informational
 *   iss    - "appwrite"
 *   pid    - project id the device belongs to (devices only)
 *   uid    - user id the device belongs to (devices only)
 */
final class Token
{
    public const SCOPE_DEVICE = 'device';
    public const SCOPE_SERVER = 'server';
    public const ALGORITHM = 'HS256';

    public function __construct(private readonly string $signingKey)
    {
        if ($signingKey === '') {
            throw new \InvalidArgumentException('Signing key cannot be empty.');
        }
    }

    /**
     * Issue a token for a device. Devices are scoped to a single topic.
     */
    public function issueForDevice(
        string $deviceId,
        string $userId,
        string $projectId,
        int $expirySeconds = 86400,
    ): string {
        $now = \time();
        $topic = self::topicForDevice($deviceId);

        return JWT::encode([
            'iss' => 'appwrite',
            'sub' => $deviceId,
            'iat' => $now,
            'exp' => $now + $expirySeconds,
            'scope' => self::SCOPE_DEVICE,
            'topic' => $topic,
            'uid' => $userId,
            'pid' => $projectId,
        ], $this->signingKey, self::ALGORITHM);
    }

    /**
     * Issue a token for a server-scoped publisher.
     */
    public function issueForServer(string $serverId, int $expirySeconds = 60): string
    {
        $now = \time();

        return JWT::encode([
            'iss' => 'appwrite',
            'sub' => $serverId,
            'iat' => $now,
            'exp' => $now + $expirySeconds,
            'scope' => self::SCOPE_SERVER,
        ], $this->signingKey, self::ALGORITHM);
    }

    /**
     * Verify a JWT and return its decoded payload, or null when invalid.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        $segments = \explode('.', $token);
        if (\count($segments) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $segments;

        $expected = \hash_hmac(
            'sha256',
            "{$headerB64}.{$payloadB64}",
            $this->signingKey,
            true,
        );

        $signature = self::decodeBase64Url($signatureB64);
        if (!\hash_equals($expected, $signature)) {
            return null;
        }

        $payload = \json_decode(self::decodeBase64Url($payloadB64), true);
        if (!\is_array($payload)) {
            return null;
        }

        if (!isset($payload['exp']) || (int)$payload['exp'] < \time()) {
            return null;
        }

        return $payload;
    }

    public static function topicForDevice(string $deviceId): string
    {
        return 'appwrite/push/' . $deviceId;
    }

    private static function decodeBase64Url(string $value): string
    {
        $padded = \str_pad(
            \strtr($value, '-_', '+/'),
            \strlen($value) % 4 === 0 ? \strlen($value) : \strlen($value) + (4 - \strlen($value) % 4),
            '=',
        );

        return \base64_decode($padded) ?: '';
    }
}
