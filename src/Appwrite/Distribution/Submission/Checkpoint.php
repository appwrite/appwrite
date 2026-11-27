<?php

namespace Appwrite\Distribution\Submission;

use Appwrite\Auth\EncryptionKey;
use Appwrite\Distribution\Submissions;
use Appwrite\OpenSSL\OpenSSL;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Distribution\Progress;
use Utopia\Lock\Exception\Contention;

/**
 * The resumable session URL is a bearer capability for the upload, so it is stored encrypted.
 */
readonly class Checkpoint implements Progress
{
    private const string SESSION = 'session';

    public function __construct(
        private Database $dbForProject,
        private string $submissionId,
        private string $claim,
    ) {
    }

    /**
     * @param array<string, mixed> $checkpoint
     */
    #[\Override]
    public function save(array $checkpoint): void
    {
        $submission = $this->dbForProject->getDocument('distributionSubmissions', $this->submissionId);
        $external = $submission->getAttribute('external', []);

        if (!\is_array($external) || ($external['claim']['id'] ?? null) !== $this->claim) {
            throw new Contention("The upload claim of submission '{$this->submissionId}' was taken over.");
        }

        $session = $checkpoint[self::SESSION] ?? null;
        if (\is_string($session)) {
            $checkpoint[self::SESSION] = self::encrypt($session);
        }

        $external['checkpoint'] = $checkpoint;
        $external['claim'] = ['id' => $this->claim, 'expiresAt' => DateTime::addSeconds(new \DateTime(), Submissions::CLAIM)];

        $this->dbForProject->updateDocument('distributionSubmissions', $this->submissionId, new Document([
            'external' => $external,
        ]));
    }

    /**
     * @param array<string, mixed> $external
     * @return array<string, mixed>|null
     */
    public static function restore(array $external): ?array
    {
        $checkpoint = $external['checkpoint'] ?? null;

        if (!\is_array($checkpoint)) {
            return null;
        }

        $session = $checkpoint[self::SESSION] ?? null;
        if (!\is_array($session)) {
            return $checkpoint;
        }

        $decrypted = self::decrypt($session);

        return $decrypted === null ? null : [...$checkpoint, self::SESSION => $decrypted];
    }

    /**
     * @return array{data: string, method: string, iv: string, tag: string, version: string}
     */
    private static function encrypt(#[\SensitiveParameter] string $value): array
    {
        $version = EncryptionKey::version();
        $key = EncryptionKey::get($version);

        if ($key === '') {
            throw new \RuntimeException('_APP_OPENSSL_KEY_V' . $version . ' is not configured, refusing to encrypt.');
        }

        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $tag = null;
        $data = OpenSSL::encrypt($value, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag);

        if ($data === false) {
            throw new \RuntimeException('The upload session could not be encrypted.');
        }

        return [
            'data' => $data,
            'method' => OpenSSL::CIPHER_AES_128_GCM,
            'iv' => \bin2hex($iv),
            'tag' => \bin2hex($tag ?? ''),
            'version' => $version,
        ];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function decrypt(array $value): ?string
    {
        $data = $value['data'] ?? null;
        $method = $value['method'] ?? null;
        $iv = \hex2bin((string) ($value['iv'] ?? ''));
        $tag = \hex2bin((string) ($value['tag'] ?? ''));

        if (!\is_string($data) || !\is_string($method) || $iv === false || $tag === false) {
            return null;
        }

        $decrypted = OpenSSL::decrypt($data, $method, EncryptionKey::get((string) ($value['version'] ?? '')), 0, $iv, $tag);

        return \is_string($decrypted) && $decrypted !== '' ? $decrypted : null;
    }
}
