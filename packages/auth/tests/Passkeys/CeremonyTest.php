<?php

declare(strict_types=1);

namespace Utopia\Auth\Tests\Passkeys;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Passkeys\Ceremony;
use Utopia\Auth\Passkeys\Credential;
use Utopia\Auth\Passkeys\Exception;
use Utopia\Auth\Passkeys\RelyingParty;

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
        $options = $this->ceremony->register('user@example.com', 'User')->options;

        $this->assertSame('localhost', Authenticator::string($options, 'rp', 'id'));
        $this->assertSame('user@example.com', Authenticator::string($options, 'user', 'name'));
        $this->assertSame('required', Authenticator::string($options, 'authenticatorSelection', 'residentKey'));
        $this->assertSame('required', Authenticator::string($options, 'authenticatorSelection', 'userVerification'));
        $this->assertSame('none', Authenticator::string($options, 'attestation'));
    }

    public function testExistingPasskeysAreExcludedAndShareTheUserHandle(): void
    {
        $authenticator = new Authenticator();
        $first = $this->register($authenticator);

        $options = $this->ceremony->register('user@example.com', 'User', [$first->record])->options;

        $this->assertSame([['type' => 'public-key', 'id' => Authenticator::encode($authenticator->credentialId), 'transports' => ['internal']]], $options['excludeCredentials']);
        $this->assertSame(Authenticator::encode($authenticator->userHandle), Authenticator::string($options, 'user', 'id'));
    }

    public function testRegisterAndSignIn(): void
    {
        $authenticator = new Authenticator();
        $registered = $this->register($authenticator);

        $signedIn = $this->signIn($authenticator, $registered);
        $this->assertSame($registered->identifier, $signedIn->identifier);
    }

    public function testIdentifyMatchesTheRegisteredPasskey(): void
    {
        $authenticator = new Authenticator();
        $registered = $this->register($authenticator);

        $challenge = $this->ceremony->authenticate();
        $this->assertSame($registered->identifier, $this->ceremony->identify($authenticator->authenticate($challenge->options, self::ORIGIN)));
    }

    public function testRecordSurvivesStorage(): void
    {
        $authenticator = new Authenticator();
        $registered = $this->register($authenticator);
        $stored = \json_decode(\json_encode($registered->record, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($stored);

        $this->assertSame($registered->identifier, $this->signIn($authenticator, new Credential($registered->identifier, $stored))->identifier);
    }

    public function testSyncedPasskeyAllowsNonIncreasingCounter(): void
    {
        $authenticator = new Authenticator(backupEligible: true);
        $credential = $this->signIn($authenticator, $this->register($authenticator));

        $authenticator->counter = 0;
        $this->assertSame($credential->identifier, $this->signIn($authenticator, $credential)->identifier);
    }

    public function testDeviceBoundPasskeyRejectsNonIncreasingCounter(): void
    {
        $authenticator = new Authenticator(backupEligible: false);
        $credential = $this->signIn($authenticator, $this->register($authenticator));

        $authenticator->counter = 0;
        $this->expectException(Exception::class);
        $this->signIn($authenticator, $credential);
    }

    public function testDeviceBoundPasskeyWithoutCounterSignsIn(): void
    {
        // Some authenticators never implement a counter and always report zero
        $authenticator = new Authenticator(backupEligible: false);
        $credential = $this->register($authenticator);

        for ($i = 0; $i < 2; $i++) {
            $authenticator->counter = -1;
            $credential = $this->signIn($authenticator, $credential);
            $this->assertNotEmpty($credential->identifier);
        }
    }

    public function testBackupEligibilityCannotChange(): void
    {
        $authenticator = new Authenticator(backupEligible: false);
        $credential = $this->register($authenticator);

        $authenticator->backupEligible = true;
        $this->expectException(Exception::class);
        $this->signIn($authenticator, $credential);
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
            'missing response' => [fn (): array => ['id' => 'abc', 'rawId' => 'abc', 'type' => 'public-key']],
        ];
    }

    /**
     * @param callable(Authenticator, array<mixed>): array<string, mixed> $credential
     */
    #[DataProvider('invalidRegistrations')]
    public function testRejectsInvalidRegistration(callable $credential): void
    {
        $challenge = $this->ceremony->register('user@example.com', 'User');

        $this->expectException(Exception::class);
        $this->ceremony->verifyRegistration($challenge->state, $credential(new Authenticator(), $challenge->options));
    }

    public function testRejectsSignInCredentialForRegistration(): void
    {
        $authenticator = new Authenticator();
        $this->register($authenticator);
        $challenge = $this->ceremony->register('user@example.com', 'User');

        $this->expectException(Exception::class);
        $this->ceremony->verifyRegistration($challenge->state, $authenticator->authenticate(['rpId' => 'localhost'] + $challenge->options, self::ORIGIN));
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
        $registered = $this->register($authenticator);
        $challenge = $this->ceremony->authenticate();

        $this->expectException(Exception::class);
        $this->ceremony->verifyAuthentication($challenge->state, $authenticator->authenticate($challenge->options, $origin, $rpId, $crossOrigin), $registered->record);
    }

    public function testRejectsSignatureFromAnotherKey(): void
    {
        $authenticator = new Authenticator();
        $registered = $this->register($authenticator);

        $impostor = new Authenticator();
        $impostor->credentialId = $authenticator->credentialId;
        $impostor->userHandle = $authenticator->userHandle;
        $impostor->counter = 10;

        $this->expectException(Exception::class);
        $this->signIn($impostor, $registered);
    }

    public function testRejectsMalformedState(): void
    {
        $this->expectException(Exception::class);
        $this->ceremony->verifyAuthentication('not json', [], []);
    }

    public function testRejectsMalformedCredentialWhenIdentifying(): void
    {
        $this->expectException(Exception::class);
        $this->ceremony->identify(['id' => 'abc']);
    }

    public function testPortlessLocalhostOriginAllowsAnyPort(): void
    {
        $ceremony = new Ceremony(new RelyingParty('localhost', 'Test', ['http://localhost']));
        $authenticator = new Authenticator();

        $challenge = $ceremony->register('user@example.com', 'User');
        $registered = $ceremony->verifyRegistration($challenge->state, $authenticator->register($challenge->options, 'http://localhost:5173'));

        $challenge = $ceremony->authenticate();
        $signedIn = $ceremony->verifyAuthentication($challenge->state, $authenticator->authenticate($challenge->options, 'http://localhost:3000'), $registered->record);
        $this->assertSame($registered->identifier, $signedIn->identifier);

        $challenge = $ceremony->authenticate();
        $this->expectException(Exception::class);
        $ceremony->verifyAuthentication($challenge->state, $authenticator->authenticate($challenge->options, 'https://localhost:3000'), $registered->record);
    }

    public function testPortlessLocalhostOriginRejectsAPath(): void
    {
        $ceremony = new Ceremony(new RelyingParty('localhost', 'Test', ['http://localhost']));
        $challenge = $ceremony->register('user@example.com', 'User');

        $this->expectException(Exception::class);
        $ceremony->verifyRegistration($challenge->state, (new Authenticator())->register($challenge->options, 'http://localhost:5173/login'));
    }

    public function testPortlessOriginAllowsOnlyDefaultPortOutsideLocalhost(): void
    {
        $ceremony = new Ceremony(new RelyingParty('example.com', 'Test', ['https://example.com']));
        $challenge = $ceremony->register('user@example.com', 'User');

        $this->expectException(Exception::class);
        $ceremony->verifyRegistration($challenge->state, (new Authenticator())->register($challenge->options, 'https://example.com:8443', 'example.com'));
    }

    public function testFingerprintIgnoresOriginOrder(): void
    {
        $a = new RelyingParty('example.com', 'A', ['https://a.example.com', 'https://example.com']);
        $b = new RelyingParty('example.com', 'B', ['https://example.com', 'https://a.example.com']);
        $c = new RelyingParty('example.com', 'A', ['https://example.com']);

        $this->assertSame($a->getFingerprint(), $b->getFingerprint());
        $this->assertNotSame($a->getFingerprint(), $c->getFingerprint());
    }

    private function register(Authenticator $authenticator): Credential
    {
        $challenge = $this->ceremony->register('user@example.com', 'User');

        return $this->ceremony->verifyRegistration($challenge->state, $authenticator->register($challenge->options, self::ORIGIN));
    }

    private function signIn(Authenticator $authenticator, Credential $credential): Credential
    {
        $challenge = $this->ceremony->authenticate();

        return $this->ceremony->verifyAuthentication($challenge->state, $authenticator->authenticate($challenge->options, self::ORIGIN), $credential->record);
    }
}
