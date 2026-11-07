<?php

namespace Tests\Compat\Auth;

use Tests\Compat\Fault;

/**
 * Software WebAuthn authenticator for the passkey cases, the stateless port
 * of packages/auth/tests/Passkeys/Authenticator.php: one ES256 key (a PEM
 * argument), so both runtimes answer a ceremony the same way.
 */
final class Authenticator
{
    private const int FLAG_USER_PRESENT = 0x01;

    private const int FLAG_USER_VERIFIED = 0x04;

    private const int FLAG_BACKUP_ELIGIBLE = 0x08;

    private const int FLAG_BACKED_UP = 0x10;

    private const int FLAG_ATTESTED = 0x40;

    /**
     * `kind` register: answers creation options; authenticate: request options.
     *
     * @param array<mixed> $a
     * @return array<string, mixed>
     */
    public static function respond(array $a): array
    {
        $key = openssl_pkey_get_private((string) $a['key']);
        if ($key === false) {
            throw new Fault('invalid authenticator key');
        }
        $options = \is_array($a['options'] ?? null) ? $a['options'] : [];
        $credentialId = self::decode((string) $a['credential_id']);
        $register = ($a['kind'] ?? '') === 'register';
        $rpId = isset($a['rp_id']) ? (string) $a['rp_id'] : ($register ? self::string($options, 'rp', 'id') : self::string($options, 'rpId'));
        $clientData = json_encode([
            'type' => $register ? 'webauthn.create' : 'webauthn.get',
            'challenge' => self::string($options, 'challenge'),
            'origin' => (string) $a['origin'],
            'crossOrigin' => (bool) ($a['cross_origin'] ?? false),
        ], JSON_THROW_ON_ERROR);

        $flags = self::FLAG_USER_PRESENT;
        if ((bool) ($a['user_verified'] ?? true)) {
            $flags |= self::FLAG_USER_VERIFIED;
        }
        if ((bool) ($a['backup_eligible'] ?? true)) {
            $flags |= self::FLAG_BACKUP_ELIGIBLE | self::FLAG_BACKED_UP;
        }
        if ($register) {
            $flags |= self::FLAG_ATTESTED;
        }
        if (isset($a['extensions'])) {
            $flags |= 0x80;
        }
        $authenticatorData = hash('sha256', $rpId, true) . pack('C', $flags) . pack('N', (int) ($a['counter'] ?? 0));

        if ($register) {
            $details = openssl_pkey_get_details($key);
            if ($details === false) {
                throw new Fault('invalid authenticator key');
            }
            $x = str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT);
            $y = str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $publicKey = isset($a['cose']) ? (string) $a['cose'] : self::head(5, 5) . self::int(1) . self::int(2) . self::int(3) . self::int(-7)
                . self::int(-1) . self::int(1) . self::int(-2) . self::bytes($x) . self::int(-3) . self::bytes($y);
            $aaguid = isset($a['aaguid']) ? (string) $a['aaguid'] : str_repeat("\0", 16);
            $authenticatorData .= $aaguid . pack('n', \strlen($credentialId)) . $credentialId . $publicKey;
            $authenticatorData .= isset($a['extensions']) ? (string) $a['extensions'] : '';
            $attestation = self::head(5, 3) . self::text('fmt') . self::text('none') . self::text('attStmt') . self::head(5, 0)
                . self::text('authData') . self::bytes($authenticatorData);

            return [
                'id' => self::encode($credentialId),
                'rawId' => self::encode($credentialId),
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => self::encode($clientData),
                    'attestationObject' => self::encode(isset($a['attestation']) ? (string) $a['attestation'] : $attestation),
                    'transports' => ['internal'],
                ],
                'clientExtensionResults' => new \stdClass(),
            ];
        }

        $authenticatorData .= isset($a['extensions']) ? (string) $a['extensions'] : '';
        $signature = isset($a['signature']) ? (string) $a['signature'] : '';
        if (!isset($a['signature']) && !openssl_sign($authenticatorData . hash('sha256', $clientData, true), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new Fault('unable to sign');
        }

        return [
            'id' => self::encode($credentialId),
            'rawId' => self::encode($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'authenticatorData' => self::encode($authenticatorData),
                'signature' => self::encode((string) $signature),
                'userHandle' => (string) ($a['user_handle'] ?? ''),
            ],
            'clientExtensionResults' => new \stdClass(),
        ];
    }

    private static function head(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => \chr(($major << 5) | $length),
            $length < 256 => \chr(($major << 5) | 24) . \chr($length),
            $length < 65536 => \chr(($major << 5) | 25) . pack('n', $length),
            default => \chr(($major << 5) | 26) . pack('N', $length),
        };
    }

    private static function int(int $value): string
    {
        return $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value);
    }

    private static function bytes(string $value): string
    {
        return self::head(2, \strlen($value)) . $value;
    }

    private static function text(string $value): string
    {
        return self::head(3, \strlen($value)) . $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string ...$path): string
    {
        $value = $data;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                throw new Fault('missing "' . implode('.', $path) . '"');
            }
            $value = $value[$key];
        }

        return \is_string($value) ? $value : throw new Fault('"' . implode('.', $path) . '" is not a string');
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
