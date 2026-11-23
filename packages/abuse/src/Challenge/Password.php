<?php

namespace Utopia\Abuse\Challenge;

use Utopia\Abuse\Challenge;

/**
 * Asks the client for a password the caller set, e.g. on a firewall rule. The
 * password is held encrypted, in the same envelope Appwrite's `encrypt` database
 * filter writes (AES-128-GCM, base64 data, hex IV and tag, key version), so it
 * is never stored or passed around in plaintext.
 *
 * It does not limit guesses: rate-limit verify() per client, e.g. with a
 * TimeLimit adapter, before calling it.
 */
final readonly class Password extends Challenge
{
    public const string CIPHER = 'aes-128-gcm';

    /** Token lifetime in seconds: how long the form stays valid. */
    public const int DEFAULT_TTL = 600;

    /** Length of {@see version()} in hex characters. */
    private const int VERSION_LENGTH = 16;

    /**
     * @param  string  $secret  Signing secret
     * @param  string  $password  The password, encrypted with {@see encrypt()} or Appwrite's `encrypt` filter
     * @param  array<array-key, string>  $keys  Decryption keys, keyed by version where known; others are tried in order during a rotation
     * @param  int  $ttl  Token lifetime in seconds
     * @param  string  $label  Derives the signing key from the secret; keep it stable or issued tokens stop verifying
     * @param  string  $purpose  Stored in each token so no other kind of token passes as one
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $secret,
        private string $password,
        private array $keys,
        int $ttl = self::DEFAULT_TTL,
        string $label = 'password',
        string $purpose = 'password',
    ) {
        if ($password === '' || $keys === []) {
            throw new \InvalidArgumentException('password and keys must not be empty');
        }

        parent::__construct($secret, $label, $purpose, $ttl);
    }

    #[\Override]
    public function issue(array $claims = []): Token
    {
        return new Token($this->sign([], $claims));
    }

    /**
     * Check a guess against the stored password.
     */
    #[\Override]
    public function verify(string $token, string $answer): Result
    {
        $claims = $this->claimsFrom($token);
        if ($claims instanceof Result) {
            return $claims;
        }

        return new Result($this->matches($answer) ? Status::Valid : Status::Rejected, $claims);
    }

    /**
     * A short identifier of the password as stored. Re-encrypting it, even to
     * the same password, changes it, so binding a clearance to it ends every
     * clearance earned with the old one.
     */
    public function version(): string
    {
        return \substr(\hash('sha256', $this->password), 0, self::VERSION_LENGTH);
    }

    /**
     * Encrypt a password for storage, recording the key version so the reader
     * can pick the right key after a rotation.
     *
     * @throws \RuntimeException when the key is empty or encryption fails
     */
    public static function encrypt(string $password, string $key, string $version): string
    {
        if ($key === '') {
            throw new \RuntimeException('Refusing to encrypt a password with an empty key.');
        }

        $iv = \random_bytes(12);
        $tag = '';
        $data = \openssl_encrypt($password, self::CIPHER, $key, 0, $iv, $tag);
        if ($data === false) {
            throw new \RuntimeException('Password encryption failed.');
        }

        return (string) \json_encode([
            'data' => $data,
            'method' => self::CIPHER,
            'iv' => \bin2hex($iv),
            'tag' => \bin2hex($tag),
            'version' => $version,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Whether the guess equals the stored password. The key whose version
     * matches the envelope is tried first, then the rest; a wrong key fails the
     * GCM tag check and never yields a match.
     */
    private function matches(string $guess): bool
    {
        $envelope = \json_decode($this->password, true);
        if (!\is_array($envelope)) {
            return false;
        }

        $data = $envelope['data'] ?? null;
        $iv = self::fromHex($envelope['iv'] ?? null);
        $tag = self::fromHex($envelope['tag'] ?? null);
        if (!\is_string($data) || ($envelope['method'] ?? null) !== self::CIPHER || $iv === null || $tag === null) {
            return false;
        }

        $version = $envelope['version'] ?? null;
        $keys = \array_values($this->keys);
        if ((\is_string($version) || \is_int($version)) && isset($this->keys[$version])) {
            \array_unshift($keys, $this->keys[$version]);
        }

        foreach ($keys as $key) {
            $password = $key === '' ? false : \openssl_decrypt($data, self::CIPHER, $key, 0, $iv, $tag);
            if ($password !== false) {
                // Compare digests so the time taken does not depend on length.
                return \hash_equals(\hash('sha256', $password), \hash('sha256', $guess));
            }
        }

        return false;
    }

    /** Decodes an even-length hex string; anything else is null. */
    private static function fromHex(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '' || \strlen($value) % 2 !== 0 || !\ctype_xdigit($value)) {
            return null;
        }

        $binary = \hex2bin($value);

        return $binary === false ? null : $binary;
    }
}
