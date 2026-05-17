<?php

namespace Appwrite\Builds;

use Appwrite\Extend\Exception;

class OrchestratorToken
{
    public static function create(string $projectId, string $resourceId, string $deploymentId, string $purpose, int $ttl = 3600): string
    {
        $expires = \time() + $ttl;
        $payload = [
            'projectId' => $projectId,
            'resourceId' => $resourceId,
            'deploymentId' => $deploymentId,
            'purpose' => $purpose,
            'expires' => $expires,
        ];

        $encoded = self::base64UrlEncode(\json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = self::sign($encoded);

        return $encoded . '.' . $signature;
    }

    public static function verify(string $token, string $projectId, string $resourceId, string $deploymentId, string $purpose): void
    {
        [$encoded, $signature] = \array_pad(\explode('.', $token, 2), 2, '');

        if (empty($encoded) || empty($signature) || !\hash_equals(self::sign($encoded), $signature)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid build artifact token.');
        }

        $payload = \json_decode(self::base64UrlDecode($encoded), true);
        if (!\is_array($payload)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid build artifact token.');
        }

        if (($payload['expires'] ?? 0) < \time()) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Build artifact token expired.');
        }

        if (
            ($payload['projectId'] ?? '') !== $projectId ||
            ($payload['resourceId'] ?? '') !== $resourceId ||
            ($payload['deploymentId'] ?? '') !== $deploymentId ||
            ($payload['purpose'] ?? '') !== $purpose
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Build artifact token mismatch.');
        }
    }

    public static function verifySignature(string $body, string $signature): void
    {
        $expected = 'sha256=' . \hash_hmac('sha256', $body, self::secret());

        if (empty($signature) || !\hash_equals($expected, $signature)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid orchestrator event signature.');
        }
    }

    private static function sign(string $payload): string
    {
        return \hash_hmac('sha256', $payload, self::secret());
    }

    private static function secret(): string
    {
        $secret = \Utopia\System\System::getEnv('_APP_ORCHESTRATOR_CALLBACK_SECRET', '');

        if (empty($secret)) {
            $secret = \Utopia\System\System::getEnv('_APP_OPENSSL_KEY_V1', '');
        }

        return $secret;
    }

    private static function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $value .= \str_repeat('=', (4 - \strlen($value) % 4) % 4);

        return \base64_decode(\strtr($value, '-_', '+/')) ?: '';
    }
}
