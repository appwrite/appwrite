<?php

namespace Utopia\Abuse\Tests\Challenge;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenge\Clearance;
use Utopia\Abuse\Challenge\Password;
use Utopia\Abuse\Challenge\Status;

final class PasswordTest extends TestCase
{
    private const string SECRET = 'password-secret';

    private const string KEY = 'fixture-openssl-key-v1-0123456789abcdef';

    private const string PREVIOUS_KEY = 'previous-openssl-key-v0-fedcba9876543210';

    private const string PASSWORD = 'correct horse battery staple';

    /**
     * Written by Appwrite's `encrypt` database filter code path
     * (Appwrite\OpenSSL\OpenSSL, AES-128-GCM) with KEY at version "1".
     */
    private const string APPWRITE_ENCRYPTED = '{"data":"cOSUHpamMn0onToPe4GIK7/1brUDlMWqXRTsng==","method":"aes-128-gcm","iv":"1a47fe5094f2d00ee01c61c2","tag":"6e5b94f73632f22ecd127a7355ed150d","version":"1"}';

    private const array CLIENT = ['ip' => '203.0.113.0/24', 'ua' => 'ua-hash'];

    /**
     * @param  array<array-key, string>  $keys
     */
    private function password(string $encrypted = self::APPWRITE_ENCRYPTED, array $keys = ['1' => self::KEY]): Password
    {
        return new Password(self::SECRET, $encrypted, $keys);
    }

    private function answer(Password $password, string $guess): Status
    {
        $bound = $password->withParams(self::CLIENT);

        return $bound->verify($bound->issue()->value, $guess)->status;
    }

    public function testAPasswordEncryptedByAppwriteMatches(): void
    {
        $this->assertSame(Status::Valid, $this->answer($this->password(), self::PASSWORD));
        $this->assertSame(Status::Rejected, $this->answer($this->password(), 'wrong'));
        $this->assertSame(Status::Rejected, $this->answer($this->password(), self::PASSWORD . ' '));
        $this->assertSame(Status::Rejected, $this->answer($this->password(), ''));
    }

    public function testEncryptWritesTheAppwriteEnvelope(): void
    {
        $envelope = \json_decode(Password::encrypt(self::PASSWORD, self::KEY, '1'), true);

        $this->assertIsArray($envelope);
        $this->assertSame(['data', 'method', 'iv', 'tag', 'version'], \array_keys($envelope));
        $this->assertSame('aes-128-gcm', $envelope['method']);
        $this->assertIsString($envelope['data']);
        $this->assertIsString($envelope['iv']);
        $this->assertIsString($envelope['tag']);

        // Plain openssl, as Appwrite's filter decrypts, returns the password.
        $this->assertSame(self::PASSWORD, \openssl_decrypt($envelope['data'], 'aes-128-gcm', self::KEY, 0, (string) \hex2bin($envelope['iv']), (string) \hex2bin($envelope['tag'])));
    }

    public function testUnlabelledKeysAreTriedInTurnDuringARotation(): void
    {
        $old = Password::encrypt(self::PASSWORD, self::PREVIOUS_KEY, '1');
        $new = Password::encrypt(self::PASSWORD, self::KEY, '2');

        $this->assertSame(Status::Valid, $this->answer($this->password($old, [self::KEY, self::PREVIOUS_KEY]), self::PASSWORD));
        $this->assertSame(Status::Valid, $this->answer($this->password($new, [self::KEY, self::PREVIOUS_KEY]), self::PASSWORD));
    }

    public function testAWrongKeyNeverMatches(): void
    {
        $this->assertSame(Status::Rejected, $this->answer($this->password(keys: ['1' => self::PREVIOUS_KEY]), self::PASSWORD));
        $this->assertSame(Status::Rejected, $this->answer($this->password(keys: ['']), self::PASSWORD));
    }

    public function testATamperedOrMalformedPasswordNeverMatches(): void
    {
        $envelope = \json_decode(self::APPWRITE_ENCRYPTED, true);
        $envelope['tag'] = \str_repeat('0', 32);

        foreach ([(string) \json_encode($envelope), 'not json', '{"data":"x","method":"aes-256-cbc","iv":"00","tag":"00","version":"1"}', '{"data":"x","method":"aes-128-gcm","iv":"zz","tag":"00","version":"1"}'] as $encrypted) {
            $this->assertSame(Status::Rejected, $this->answer($this->password($encrypted), self::PASSWORD), $encrypted);
        }
    }

    public function testATokenVerifiesOnlyForTheSameParams(): void
    {
        $token = $this->password()->withParams(self::CLIENT)->issue();

        $this->assertSame(Status::BindingMismatch, $this->password()->verify($token->value, self::PASSWORD)->status);
        $this->assertSame(Status::BindingMismatch, $this->password()->withParams([...self::CLIENT, 'ip' => '198.51.100.0/24'])->verify($token->value, self::PASSWORD)->status);
        $this->assertSame(Status::Valid, $this->password()->withParams(self::CLIENT)->verify($token->value, self::PASSWORD)->status);
    }

    public function testATokenFromAnotherChallengeIsRejected(): void
    {
        $other = new Password('other-secret', self::APPWRITE_ENCRYPTED, ['1' => self::KEY])->issue();
        $clearance = new Clearance(self::SECRET)->issue();

        $this->assertSame(Status::InvalidToken, $this->password()->verify($other->value, self::PASSWORD)->status);
        $this->assertSame(Status::InvalidToken, $this->password()->verify($clearance, self::PASSWORD)->status);
    }

    public function testVersionChangesWhenThePasswordIsEncryptedAgain(): void
    {
        $first = $this->password(Password::encrypt(self::PASSWORD, self::KEY, '1'));
        $second = $this->password(Password::encrypt(self::PASSWORD, self::KEY, '1'));

        $this->assertSame($first->version(), $first->version());
        $this->assertNotSame($first->version(), $second->version());
        $this->assertSame(16, \strlen($first->version()));
    }

    public function testAClearanceBoundToTheVersionEndsWhenThePasswordChanges(): void
    {
        $clearance = new Clearance(self::SECRET);
        $before = $this->password(Password::encrypt(self::PASSWORD, self::KEY, '1'));
        $after = $this->password(Password::encrypt('new password', self::KEY, '1'));

        $pass = $clearance->withParams([...self::CLIENT, 'version' => $before->version()])->issue();

        $this->assertTrue($clearance->withParams([...self::CLIENT, 'version' => $before->version()])->verify($pass));
        $this->assertFalse($clearance->withParams([...self::CLIENT, 'version' => $after->version()])->verify($pass));
    }

    public function testEncryptRefusesAnEmptyKey(): void
    {
        $this->expectException(\RuntimeException::class);
        Password::encrypt(self::PASSWORD, '', '1');
    }

    public function testAnEmptyPasswordOrNoKeysIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Password(self::SECRET, '', ['1' => self::KEY]);
    }
}
