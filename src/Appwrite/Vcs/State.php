<?php

namespace Appwrite\Vcs;

/**
 * The state a VCS authorize action hands to the provider and its callback
 * reads back: the project and redirect URLs, bound to the console user who
 * started the flow, with an expiry, under an HMAC of the server key.
 */
readonly class State
{
    public const int TTL = 3600;

    public function __construct(
        public string $projectId,
        public string $userId,
        public string $success,
        public string $failure,
        public int $expire,
        public string $nonce,
    ) {
    }

    public static function create(string $projectId, string $userId, string $success, string $failure): self
    {
        return new self($projectId, $userId, $success, $failure, \time() + self::TTL, \bin2hex(\random_bytes(16)));
    }

    /**
     * @return array<string, string|int>
     */
    public function sign(string $key): array
    {
        return [
            'projectId' => $this->projectId,
            'userId' => $this->userId,
            'success' => $this->success,
            'failure' => $this->failure,
            'expire' => $this->expire,
            'nonce' => $this->nonce,
            'signature' => $this->signature($key),
        ];
    }

    /**
     * The signed state as an opaque token, for a provider that takes the state
     * as one instead of echoing JSON. `verify()` reads either form.
     */
    public function encode(string $key): string
    {
        return \rtrim(\strtr(\base64_encode((string) \json_encode($this->sign($key), JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    /**
     * The state signed with the key, or null when it is malformed or the signature does not match.
     */
    public static function verify(string $state, string $key): ?self
    {
        $decoded = \json_decode(self::decode($state), true);

        if (!\is_array($decoded)) {
            return null;
        }

        foreach (['projectId', 'userId', 'success', 'failure', 'nonce', 'signature'] as $field) {
            if (!\is_string($decoded[$field] ?? null)) {
                return null;
            }
        }

        if (!\is_int($decoded['expire'] ?? null)) {
            return null;
        }

        $verified = new self(
            $decoded['projectId'],
            $decoded['userId'],
            $decoded['success'],
            $decoded['failure'],
            $decoded['expire'],
            $decoded['nonce'],
        );

        if (!\hash_equals($verified->signature($key), $decoded['signature'])) {
            return null;
        }

        return $verified;
    }

    public function isExpired(): bool
    {
        return $this->expire < \time();
    }

    /**
     * The signed JSON, from either the plain or the base64url-encoded form
     * `encode()` produces. JSON is not valid base64url, so it falls through.
     */
    private static function decode(string $state): string
    {
        $decoded = \base64_decode(\strtr($state, '-_', '+/'), true);

        return $decoded !== false && \str_starts_with($decoded, '{') ? $decoded : $state;
    }

    private function signature(string $key): string
    {
        return \hash_hmac('sha256', (string) \json_encode([
            $this->projectId,
            $this->userId,
            $this->success,
            $this->failure,
            $this->expire,
            $this->nonce,
        ]), $key);
    }
}
