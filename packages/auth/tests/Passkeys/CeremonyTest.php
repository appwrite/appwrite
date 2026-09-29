<?php

declare(strict_types=1);

namespace Utopia\Auth\Tests\Passkeys;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Passkeys\Ceremony;
use Utopia\Auth\Passkeys\RelyingParty;
use Webauthn\CredentialRecord;
use Webauthn\Exception\WebauthnException;

final class CeremonyTest extends TestCase
{
    private const string ORIGIN = 'http://localhost:3000';

    private Ceremony $ceremony;

    protected function setUp(): void
    {
        $this->ceremony = new Ceremony(new RelyingParty('localhost', 'Test', [self::ORIGIN]));
    }

    public function testRegistrationOptions(): void
    {
        $exclude = \random_bytes(16);
        $options = $this->decode($this->ceremony->encode($this->ceremony->createRegistration(\random_bytes(32), 'user@example.com', 'User', [$exclude])));

        $this->assertSame('localhost', Authenticator::string($options, 'rp', 'id'));
        $this->assertSame('user@example.com', Authenticator::string($options, 'user', 'name'));
        $this->assertSame('required', Authenticator::string($options, 'authenticatorSelection', 'residentKey'));
        $this->assertSame('required', Authenticator::string($options, 'authenticatorSelection', 'userVerification'));
        $this->assertSame('none', Authenticator::string($options, 'attestation'));
        $this->assertSame([['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]], $options['pubKeyCredParams']);
        $this->assertSame([['type' => 'public-key', 'id' => Authenticator::encode($exclude)]], $options['excludeCredentials']);
        $this->assertSame(Ceremony::TIMEOUT * 1000, $options['timeout']);
    }

    public function testRegisterAndSignIn(): void
    {
        $authenticator = new Authenticator();
        $record = $this->register($authenticator);

        $this->assertSame($authenticator->credentialId, $record->publicKeyCredentialId);
        $this->assertTrue($record->backupEligible);
        $this->assertTrue($record->uvInitialized);

        $record = $this->signIn($authenticator, $record);
        $this->assertSame(1, $record->counter);
    }

    public function testRecordSurvivesStorage(): void
    {
        $authenticator = new Authenticator();
        $stored = $this->ceremony->encodeRecord($this->register($authenticator));

        $record = $this->signIn($authenticator, $this->ceremony->decodeRecord($this->decode(\json_encode($stored, JSON_THROW_ON_ERROR))));
        $this->assertSame(1, $record->counter);
    }

    public function testSyncedPasskeyAllowsNonIncreasingCounter(): void
    {
        $authenticator = new Authenticator(backupEligible: true);
        $record = $this->signIn($authenticator, $this->register($authenticator));

        $authenticator->counter = 0;
        $this->assertSame(1, $this->signIn($authenticator, $record)->counter);
    }

    public function testDeviceBoundPasskeyRejectsNonIncreasingCounter(): void
    {
        $authenticator = new Authenticator(backupEligible: false);
        $record = $this->signIn($authenticator, $this->register($authenticator));

        $authenticator->counter = 0;
        $this->expectException(WebauthnException::class);
        $this->signIn($authenticator, $record);
    }

    public function testBackupEligibilityCannotChange(): void
    {
        $authenticator = new Authenticator(backupEligible: false);
        $record = $this->register($authenticator);

        $authenticator->backupEligible = true;
        $this->expectException(WebauthnException::class);
        $this->signIn($authenticator, $record);
    }

    /**
     * @return array<string, array{callable(Authenticator, array<mixed>): array<string, mixed>}>
     */
    public static function invalidRegistrations(): array
    {
        return [
            'wrong origin' => [fn (Authenticator $a, array $o): array => $a->register($o, 'http://localhost:4000')],
            'subdomain origin' => [fn (Authenticator $a, array $o): array => $a->register($o, 'http://evil.localhost:3000')],
            'wrong rp id' => [fn (Authenticator $a, array $o): array => $a->register($o, self::ORIGIN, 'example.com')],
            'other challenge' => [fn (Authenticator $a, array $o): array => $a->register(['challenge' => Authenticator::encode(\random_bytes(32))] + $o, self::ORIGIN)],
            'no user verification' => [function (Authenticator $a, array $o): array {
                $a->userVerified = false;
                return $a->register($o, self::ORIGIN);
            }],
            'malformed' => [fn (): array => ['id' => 'abc', 'type' => 'public-key', 'response' => ['clientDataJSON' => 'e30']]],
        ];
    }

    /**
     * @param callable(Authenticator, array<mixed>): array<string, mixed> $credential
     */
    #[DataProvider('invalidRegistrations')]
    public function testRejectsInvalidRegistration(callable $credential): void
    {
        $options = $this->ceremony->createRegistration(\random_bytes(32), 'user@example.com', 'User', []);
        $json = $this->ceremony->encode($options);

        $this->expectException(WebauthnException::class);
        $this->ceremony->verifyRegistration(
            $this->ceremony->decodeCredential($credential(new Authenticator(), $this->decode($json))),
            $this->ceremony->decodeRegistration($json),
        );
    }

    /**
     * @return array<string, array{string, ?string, bool}>
     */
    public static function invalidSignIns(): array
    {
        return [
            'wrong origin' => ['http://localhost:4000', null, false],
            'wrong rp id' => [self::ORIGIN, 'example.com', false],
            'cross-origin iframe' => [self::ORIGIN, null, true],
        ];
    }

    #[DataProvider('invalidSignIns')]
    public function testRejectsInvalidSignIn(string $origin, ?string $rpId, bool $crossOrigin): void
    {
        $authenticator = new Authenticator();
        $record = $this->register($authenticator);

        $json = $this->ceremony->encode($this->ceremony->createAuthentication());
        $credential = $authenticator->authenticate($this->decode($json), $origin, $rpId, $crossOrigin);

        $this->expectException(WebauthnException::class);
        $this->ceremony->verifyAuthentication($this->ceremony->decodeCredential($credential), $record, $this->ceremony->decodeAuthentication($json));
    }

    public function testRejectsSignatureFromAnotherKey(): void
    {
        $authenticator = new Authenticator();
        $record = $this->register($authenticator);

        $impostor = new Authenticator();
        $impostor->credentialId = $authenticator->credentialId;
        $impostor->userHandle = $authenticator->userHandle;
        $impostor->counter = 10;

        $this->expectException(WebauthnException::class);
        $this->signIn($impostor, $record);
    }

    public function testIdentifierIsStableDigest(): void
    {
        $this->assertSame(\hash('sha256', 'credential'), Ceremony::getIdentifier('credential'));
        $this->assertSame(64, \strlen(Ceremony::getIdentifier(\random_bytes(16))));
    }

    public function testFingerprintIgnoresOriginOrder(): void
    {
        $a = new RelyingParty('example.com', 'A', ['https://a.example.com', 'https://example.com']);
        $b = new RelyingParty('example.com', 'B', ['https://example.com', 'https://a.example.com']);
        $c = new RelyingParty('example.com', 'A', ['https://example.com']);

        $this->assertSame($a->getFingerprint(), $b->getFingerprint());
        $this->assertNotSame($a->getFingerprint(), $c->getFingerprint());
    }

    private function register(Authenticator $authenticator): CredentialRecord
    {
        $json = $this->ceremony->encode($this->ceremony->createRegistration(\random_bytes(32), 'user@example.com', 'User', []));

        return $this->ceremony->verifyRegistration(
            $this->ceremony->decodeCredential($authenticator->register($this->decode($json), self::ORIGIN)),
            $this->ceremony->decodeRegistration($json),
        );
    }

    private function signIn(Authenticator $authenticator, CredentialRecord $record): CredentialRecord
    {
        $json = $this->ceremony->encode($this->ceremony->createAuthentication());

        return $this->ceremony->verifyAuthentication(
            $this->ceremony->decodeCredential($authenticator->authenticate($this->decode($json), self::ORIGIN)),
            $record,
            $this->ceremony->decodeAuthentication($json),
        );
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $json): array
    {
        $decoded = \json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Expected a JSON object');
        }

        return $decoded;
    }
}
