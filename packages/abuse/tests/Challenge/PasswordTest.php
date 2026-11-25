<?php

namespace Utopia\Abuse\Tests\Challenge;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenge\Clearance;
use Utopia\Abuse\Challenge\Password;
use Utopia\Abuse\Challenge\Status;
use Utopia\Auth\Hashes\Bcrypt;
use Utopia\Auth\Hashes\PHPass;
use Utopia\Auth\Hashes\Scrypt;
use Utopia\Auth\Proofs\Password as PasswordProof;

final class PasswordTest extends TestCase
{
    private const string SECRET = 'password-secret';

    private const string PASSWORD = 'correct horse battery staple';

    /** A hash Appwrite stores for the password "appwrite" (its own e2e fixture). */
    private const string APPWRITE_HASH = '$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI';

    /** A portable PHPass hash of "open sesame" ($P$, as WordPress stores them). */
    private const string PHPASS_HASH = '$P$6abcdefghCj/oL.5zumm7o2h0nT5970';

    private const array CLIENT = ['ip' => '203.0.113.0/24', 'ua' => 'ua-hash'];

    private function password(string $hash): Password
    {
        return new Password(self::SECRET, $hash);
    }

    private function answer(Password $password, string $guess): Status
    {
        $bound = $password->withParams(self::CLIENT);

        return $bound->verify($bound->issue()->value, $guess)->status;
    }

    public function testAPasswordHashedByAuthMatches(): void
    {
        $password = $this->password(new PasswordProof()->hash(self::PASSWORD));

        $this->assertSame(Status::Valid, $this->answer($password, self::PASSWORD));
        $this->assertSame(Status::Rejected, $this->answer($password, 'wrong'));
        $this->assertSame(Status::Rejected, $this->answer($password, self::PASSWORD . ' '));
    }

    public function testAHashAppwriteStoresMatches(): void
    {
        $password = $this->password(self::APPWRITE_HASH);

        $this->assertSame(Status::Valid, $this->answer($password, 'appwrite'));
        $this->assertSame(Status::Rejected, $this->answer($password, 'Appwrite'));
    }

    public function testTheDefaultProofChecksABcryptHash(): void
    {
        $password = $this->password(new Bcrypt()->hash(self::PASSWORD));

        $this->assertSame(Status::Valid, $this->answer($password, self::PASSWORD));
        $this->assertSame(Status::Rejected, $this->answer($password, 'wrong'));
    }

    public function testAScryptHashNeedsAProofSetToScrypt(): void
    {
        $scrypt = new Scrypt();
        $hash = $scrypt->hash(self::PASSWORD);

        $this->assertSame(Status::Rejected, $this->answer($this->password($hash), self::PASSWORD));

        $password = new Password(self::SECRET, $hash, new PasswordProof()->setHash($scrypt));
        $this->assertSame(Status::Valid, $this->answer($password, self::PASSWORD));
        $this->assertSame(Status::Rejected, $this->answer($password, 'wrong'));
    }

    public function testAPhpassHashNeedsAProofSetToPhpass(): void
    {
        $this->assertSame(Status::Rejected, $this->answer($this->password(self::PHPASS_HASH), 'open sesame'));

        $password = new Password(self::SECRET, self::PHPASS_HASH, new PasswordProof()->setHash(new PHPass()));
        $this->assertSame(Status::Valid, $this->answer($password, 'open sesame'));
        $this->assertSame(Status::Rejected, $this->answer($password, 'wrong'));
    }

    public function testAnEmptyGuessNeverMatches(): void
    {
        $this->assertSame(Status::Rejected, $this->answer($this->password(new PasswordProof()->hash(self::PASSWORD)), ''));
        // Not even against a hash of an empty password.
        $this->assertSame(Status::Rejected, $this->answer($this->password(new PasswordProof()->hash('')), ''));
    }

    public function testAMalformedHashNeverMatches(): void
    {
        $this->assertSame(Status::Rejected, $this->answer($this->password('not-a-hash'), self::PASSWORD));
    }

    public function testAnEmptyHashIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->password('');
    }

    public function testATokenVerifiesOnlyForTheSameParams(): void
    {
        $password = $this->password(new PasswordProof()->hash(self::PASSWORD));
        $token = $password->withParams(self::CLIENT)->issue();

        $this->assertSame(Status::BindingMismatch, $password->verify($token->value, self::PASSWORD)->status);
        $this->assertSame(Status::BindingMismatch, $password->withParams([...self::CLIENT, 'ip' => '198.51.100.0/24'])->verify($token->value, self::PASSWORD)->status);
        $this->assertSame(Status::Valid, $password->withParams(self::CLIENT)->verify($token->value, self::PASSWORD)->status);
    }

    public function testATokenFromAnotherChallengeIsRejected(): void
    {
        $hash = new PasswordProof()->hash(self::PASSWORD);
        $other = new Password('other-secret', $hash)->issue();
        $clearance = new Clearance(self::SECRET)->issue();

        $this->assertSame(Status::InvalidToken, $this->password($hash)->verify($other->value, self::PASSWORD)->status);
        $this->assertSame(Status::InvalidToken, $this->password($hash)->verify($clearance, self::PASSWORD)->status);
    }

    public function testVersionChangesWhenThePasswordIsHashedAgain(): void
    {
        $first = $this->password(new PasswordProof()->hash(self::PASSWORD));
        $second = $this->password(new PasswordProof()->hash(self::PASSWORD));

        $this->assertSame($first->version(), $first->version());
        $this->assertNotSame($first->version(), $second->version());
        $this->assertSame(16, \strlen($first->version()));
    }

    public function testAClearanceBoundToTheVersionEndsWhenThePasswordChanges(): void
    {
        $clearance = new Clearance(self::SECRET);
        $before = $this->password(new PasswordProof()->hash(self::PASSWORD));
        $after = $this->password(new PasswordProof()->hash('new password'));

        $pass = $clearance->withParams([...self::CLIENT, 'version' => $before->version()])->issue();

        $this->assertTrue($clearance->withParams([...self::CLIENT, 'version' => $before->version()])->verify($pass));
        $this->assertFalse($clearance->withParams([...self::CLIENT, 'version' => $after->version()])->verify($pass));
    }
}
