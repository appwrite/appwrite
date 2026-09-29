<?php

namespace Tests\E2E\Services\Account;

use CBOR\ByteStringObject;
use CBOR\MapItem;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;

/**
 * Software WebAuthn authenticator holding one ES256 passkey, used to drive
 * registration and sign-in ceremonies without a browser.
 */
class Authenticator
{
    private const int FLAG_USER_PRESENT = 0x01;
    private const int FLAG_USER_VERIFIED = 0x04;
    private const int FLAG_BACKUP_ELIGIBLE = 0x08;
    private const int FLAG_BACKED_UP = 0x10;
    private const int FLAG_ATTESTED = 0x40;

    public string $credentialId;

    public string $userHandle = '';

    public int $counter = 0;

    private \OpenSSLAsymmetricKey $key;

    public function __construct(
        public bool $backupEligible = true,
        public bool $userVerified = true,
    ) {
        $this->credentialId = \random_bytes(16);
        $key = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('Unable to create key');
        }
        $this->key = $key;
    }

    /**
     * @param array<string, mixed> $options creation options returned by createPasskey
     * @return array<string, mixed> PublicKeyCredential.toJSON() shape
     */
    public function register(array $options, string $origin, ?string $rpId = null): array
    {
        $rpId ??= $options['rp']['id'];
        $this->userHandle = self::decode($options['user']['id']);

        $clientData = \json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['challenge'],
            'origin' => $origin,
            'crossOrigin' => false,
        ]);

        $details = \openssl_pkey_get_details($this->key);
        $publicKey = new MapObject([
            MapItem::create(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)),
            MapItem::create(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)),
            MapItem::create(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)),
            MapItem::create(NegativeIntegerObject::create(-2), ByteStringObject::create(\str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT))),
            MapItem::create(NegativeIntegerObject::create(-3), ByteStringObject::create(\str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))),
        ]);

        $authenticatorData = $this->getAuthenticatorData($rpId, self::FLAG_ATTESTED)
            . \str_repeat("\0", 16)
            . \pack('n', \strlen($this->credentialId))
            . $this->credentialId
            . (string) $publicKey;

        $attestation = new MapObject([
            MapItem::create(TextStringObject::create('fmt'), TextStringObject::create('none')),
            MapItem::create(TextStringObject::create('attStmt'), new MapObject([])),
            MapItem::create(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData)),
        ]);

        return [
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'attestationObject' => self::encode((string) $attestation),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => new \stdClass(),
        ];
    }

    /**
     * @param array<string, mixed> $options request options returned by createPasskeyToken
     * @return array<string, mixed> PublicKeyCredential.toJSON() shape
     */
    public function authenticate(array $options, string $origin, ?string $rpId = null, bool $crossOrigin = false): array
    {
        $rpId ??= $options['rpId'];
        $this->counter++;

        $clientData = \json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options['challenge'],
            'origin' => $origin,
            'crossOrigin' => $crossOrigin,
        ]);

        $authenticatorData = $this->getAuthenticatorData($rpId, 0);

        \openssl_sign($authenticatorData . \hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'authenticatorData' => self::encode($authenticatorData),
                'signature' => self::encode($signature),
                'userHandle' => self::encode($this->userHandle),
            ],
            'clientExtensionResults' => new \stdClass(),
        ];
    }

    private function getAuthenticatorData(string $rpId, int $flags): string
    {
        $flags |= self::FLAG_USER_PRESENT;
        if ($this->userVerified) {
            $flags |= self::FLAG_USER_VERIFIED;
        }
        if ($this->backupEligible) {
            $flags |= self::FLAG_BACKUP_ELIGIBLE | self::FLAG_BACKED_UP;
        }

        return \hash('sha256', $rpId, true) . \chr($flags) . \pack('N', $this->counter);
    }

    public static function encode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        return \base64_decode(\strtr($value, '-_', '+/'));
    }
}
