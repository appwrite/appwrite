<?php

namespace Utopia\Abuse\Challenge;

use Utopia\Abuse\Challenge;
use Utopia\Auth\Proof;
use Utopia\Auth\Proofs\Password as PasswordProof;

/**
 * Asks the client for a password the caller set, e.g. on a firewall rule. Only
 * a hash of it is kept, checked with utopia-php/auth's password proof. Create
 * one with `new \Utopia\Auth\Proofs\Password()->hash(...)` (Argon2, as Appwrite
 * stores user passwords). The default proof also checks Bcrypt; for PHPass
 * (`$P$`), Scrypt, SHA or MD5 hashes pass a proof set to that hash.
 *
 * It does not limit guesses: rate-limit verify() per client before calling it.
 */
final readonly class Password extends Challenge
{
    /** Token lifetime in seconds: how long the form stays valid. */
    public const int DEFAULT_TTL = 600;

    /** Length of {@see version()} in hex characters. */
    private const int VERSION_LENGTH = 16;

    /**
     * @param  string  $secret  Signing secret
     * @param  string  $hash  The password's hash, from utopia-php/auth
     * @param  Proof  $proof  Checks a guess against the hash; set it to the hash's algorithm unless that is Argon2 or Bcrypt
     * @param  int  $ttl  Token lifetime in seconds
     * @param  string  $label  Derives the signing key from the secret; keep it stable or issued tokens stop verifying
     * @param  string  $purpose  Stored in each token so no other kind of token passes as one
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $secret,
        private string $hash,
        private Proof $proof = new PasswordProof(),
        int $ttl = self::DEFAULT_TTL,
        string $label = 'password',
        string $purpose = 'password',
    ) {
        if ($hash === '') {
            throw new \InvalidArgumentException('hash must not be empty');
        }

        parent::__construct($secret, $label, $purpose, $ttl);
    }

    #[\Override]
    public function issue(array $claims = []): Token
    {
        return new Token($this->sign([], $claims));
    }

    /**
     * Check a guess against the stored hash. An empty guess never matches, so a
     * hash of an empty password can't let anyone through.
     */
    #[\Override]
    public function verify(string $token, string $answer): Result
    {
        $claims = $this->claimsFrom($token);
        if ($claims instanceof Result) {
            return $claims;
        }

        $matches = $answer !== '' && $this->proof->verify($answer, $this->hash);

        return new Result($matches ? Status::Valid : Status::Rejected, $claims);
    }

    /**
     * A short identifier of the stored hash. Hashing again, even the same
     * password, changes it (new salt), so binding a clearance to it ends every
     * clearance earned with the old password.
     */
    public function version(): string
    {
        return \substr(\hash('sha256', $this->hash), 0, self::VERSION_LENGTH);
    }
}
