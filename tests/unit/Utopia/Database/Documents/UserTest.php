<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Documents;

use Appwrite\Auth\MFA\Type;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Utopia\Database\Documents\Fakes\RacingDatabase;
use Utopia\Auth\Proofs\Code;
use Utopia\Auth\Proofs\Token;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Roles;

final class UserTest extends TestCase
{
    private $authorization;

    private string|false $key;

    protected function setUp(): void
    {
        // The identities collection runs its tokens through the encrypt filter on every write
        $this->key = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');
    }

    public function getAuthorization(): Authorization
    {
        if (isset($this->authorization)) {
            return $this->authorization;
        }

        $this->authorization = new Authorization();
        return $this->authorization;
    }

    /**
     * Reset Roles
     */
    public function tearDown(): void
    {
        \putenv($this->key === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->key);

        $this->getAuthorization()->cleanRoles();
        $this->getAuthorization()->addRole(Role::any()->toString());
    }

    public function testSessionVerify(): void
    {
        $proofForToken = new Token();
        $expireTime1 = 60 * 60 * 24;

        $secret = 'secret1';
        $hash = $proofForToken->hash($secret);
        $tokens1 = [
            new Document([
                '$id' => Id::custom('token1'),
                'secret' => $hash,
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
                'expire' => DateTime::addSeconds(new \DateTime(), $expireTime1),
            ]),
            new Document([
                '$id' => Id::custom('token2'),
                'secret' => 'secret2',
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
                'expire' => DateTime::addSeconds(new \DateTime(), $expireTime1),
            ]),
        ];

        $expireTime2 = -60 * 60 * 24;

        $tokens2 = [
            new Document([ // Correct secret and type time, wrong expire time
                '$id' => Id::custom('token1'),
                'secret' => $hash,
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
                'expire' => DateTime::addSeconds(new \DateTime(), $expireTime2),
            ]),
            new Document([
                '$id' => Id::custom('token2'),
                'secret' => 'secret2',
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
                'expire' => DateTime::addSeconds(new \DateTime(), $expireTime2),
            ]),
        ];

        $user1 = new User([
            '$id' => Id::custom('user1'),
            'sessions' => $tokens1,

        ]);

        $user2 = new User([
            '$id' => Id::custom('user2'),
            'sessions' => $tokens2,
        ]);

        $this->assertEquals('token1', $user1->sessionVerify($secret, $proofForToken, null));
        $this->assertEquals(false, $user1->sessionVerify('false-secret', $proofForToken, null));
        $this->assertEquals(false, $user2->sessionVerify($secret, $proofForToken, null));
        $this->assertEquals(false, $user2->sessionVerify('false-secret', $proofForToken, null));
    }

    public function testSessionActive(): void
    {
        $user = new User([
            '$id' => Id::custom('user1'),
            'sessions' => [
                new Document([
                    '$id' => Id::custom('active'),
                    'secret' => 'secret',
                    'provider' => SESSION_PROVIDER_EMAIL,
                    'expire' => DateTime::addSeconds(new \DateTime(), 60 * 60),
                ]),
                new Document([
                    '$id' => Id::custom('expired'),
                    'secret' => 'secret',
                    'provider' => SESSION_PROVIDER_EMAIL,
                    'expire' => DateTime::addSeconds(new \DateTime(), -60),
                ]),
                new Document([
                    '$id' => Id::custom('missing-expire'),
                    'secret' => 'secret',
                    'provider' => SESSION_PROVIDER_EMAIL,
                ]),
            ],
        ]);

        $this->assertTrue($user->sessionActive('active', null));
        $this->assertFalse($user->sessionActive('expired', null));
        $this->assertFalse($user->sessionActive('missing-expire', null));
        $this->assertFalse($user->sessionActive('missing', null));
    }

    public static function sessionVerifyInvalidationProvider(): \Iterator
    {
        $secret = 'secret1';
        $proofForToken = new Token();
        $hash = $proofForToken->hash($secret);
        yield 'session before invalidation is rejected' => [
            $hash,
            $secret,
            $proofForToken,
            DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -120)), // createdAt: 2 minutes ago
            DateTime::formatTz(DateTime::now()),                              // invalidatedAt: now
            false,
        ];
        yield 'session at invalidation is rejected (<=)' => [
            $hash,
            $secret,
            $proofForToken,
            DateTime::formatTz(DateTime::now()),                              // createdAt == invalidatedAt
            DateTime::formatTz(DateTime::now()),
            false,
        ];
        yield 'session after invalidation passes' => [
            $hash,
            $secret,
            $proofForToken,
            DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 60)),    // createdAt: future
            DateTime::formatTz(DateTime::now()),                              // invalidatedAt: now
            'sessionId',
        ];
        yield 'null invalidatedAt — existing behavior unchanged' => [
            $hash,
            $secret,
            $proofForToken,
            DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -120)),
            null,
            'sessionId',
        ];
    }

    #[DataProvider('sessionVerifyInvalidationProvider')]
    public function testSessionVerifyInvalidation(string $hash, string $secret, Token $proofForToken, string $createdAt, ?string $invalidatedAt, string|bool $expected): void
    {
        $user = new User([
            '$id' => Id::custom('sessionId'),
            'sessions' => [
                new Document([
                    '$id' => Id::custom('sessionId'),
                    '$createdAt' => $createdAt,
                    'secret' => $hash,
                    'provider' => SESSION_PROVIDER_EMAIL,
                    'providerUid' => 'test@example.com',
                    'expire' => DateTime::addSeconds(new \DateTime(), 60 * 60),
                ]),
            ],
        ]);

        $result = $user->sessionVerify($secret, $proofForToken, $invalidatedAt);
        $this->assertSame($expected, $result);
    }

    public static function sessionActiveInvalidationProvider(): \Iterator
    {
        yield 'session before invalidation is rejected' => [
            DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -120)), // createdAt: 2 minutes ago
            DateTime::formatTz(DateTime::now()),                              // invalidatedAt: now
            false,
        ];
        yield 'session at invalidation is rejected (<=)' => [
            DateTime::formatTz(DateTime::now()),
            DateTime::formatTz(DateTime::now()),
            false,
        ];
        yield 'session after invalidation passes' => [
            DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 60)),
            DateTime::formatTz(DateTime::now()),
            true,
        ];
        yield 'null invalidatedAt — existing behavior unchanged' => [
            DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -120)),
            null,
            true,
        ];
    }

    #[DataProvider('sessionActiveInvalidationProvider')]
    public function testSessionActiveInvalidation(string $createdAt, ?string $invalidatedAt, bool $expected): void
    {
        $user = new User([
            '$id' => Id::custom('user1'),
            'sessions' => [
                new Document([
                    '$id' => Id::custom('active'),
                    '$createdAt' => $createdAt,
                    'secret' => 'secret',
                    'provider' => SESSION_PROVIDER_EMAIL,
                    'expire' => DateTime::addSeconds(new \DateTime(), 60 * 60),
                ]),
            ],
        ]);

        $this->assertSame($expected, $user->sessionActive('active', $invalidatedAt));
    }

    public function testGetSessionExpiry(): void
    {
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 60 * 60));
        $user = new User([
            '$id' => Id::custom('user1'),
            'sessions' => [
                new Document([
                    '$id' => Id::custom('session'),
                    'secret' => 'secret',
                    'provider' => SESSION_PROVIDER_EMAIL,
                    'expire' => $expire,
                ]),
                new Document([
                    '$id' => Id::custom('missing-expire'),
                    'secret' => 'secret',
                    'provider' => SESSION_PROVIDER_EMAIL,
                ]),
            ],
        ]);

        $this->assertSame((new \DateTime($expire))->getTimestamp(), $user->getSessionExpiry('session'));
        $this->assertNull($user->getSessionExpiry('missing-expire'));
        $this->assertNull($user->getSessionExpiry('missing'));
    }

    public function testGetMinimumFactors(): void
    {
        $this->assertSame(1, (new User(['mfa' => false, 'emailVerification' => true]))->getMinimumFactors());
        $this->assertSame(1, (new User(['mfa' => true]))->getMinimumFactors());
        $this->assertSame(2, (new User(['mfa' => true, 'emailVerification' => true]))->getMinimumFactors());
        $this->assertSame(2, (new User(['mfa' => true, 'phoneVerification' => true]))->getMinimumFactors());
        $this->assertSame(1, (new User([
            'mfa' => true,
            'authenticators' => [new Document(['type' => Type::TOTP, 'verified' => false])],
        ]))->getMinimumFactors());
        $this->assertSame(2, (new User([
            'mfa' => true,
            'authenticators' => [new Document(['type' => Type::TOTP, 'verified' => true])],
        ]))->getMinimumFactors());
    }

    public function testSessionFactorsMet(): void
    {
        $user = new User([
            '$id' => Id::custom('user1'),
            'mfa' => true,
            'emailVerification' => true,
            'sessions' => [
                new Document(['$id' => Id::custom('pending'), 'factors' => ['password']]),
                new Document(['$id' => Id::custom('complete'), 'factors' => ['password', Type::EMAIL]]),
                new Document(['$id' => Id::custom('none')]),
            ],
        ]);

        $this->assertFalse($user->sessionFactorsMet('pending'));
        $this->assertTrue($user->sessionFactorsMet('complete'));
        $this->assertFalse($user->sessionFactorsMet('none'));
        $this->assertFalse($user->sessionFactorsMet('missing'));

        $user->setAttribute('mfa', false);
        $this->assertTrue($user->sessionFactorsMet('pending'));
    }

    public function testSessionChallengeRecent(): void
    {
        $user = new User([
            '$id' => Id::custom('user1'),
            'sessions' => [
                new Document(['$id' => Id::custom('recent'), 'mfaUpdatedAt' => DateTime::addSeconds(new \DateTime(), -60)]),
                new Document(['$id' => Id::custom('stale'), 'mfaUpdatedAt' => DateTime::addSeconds(new \DateTime(), -MFA_RECENT_DURATION - 60)]),
                new Document(['$id' => Id::custom('never')]),
            ],
        ]);

        $this->assertTrue($user->sessionChallengeRecent('recent'));
        $this->assertFalse($user->sessionChallengeRecent('stale'));
        $this->assertFalse($user->sessionChallengeRecent('never'));
        $this->assertFalse($user->sessionChallengeRecent('missing'));
    }

    public function testTokenVerify(): void
    {
        $proofForToken = new Token();
        $secret = 'secret1';
        $hash = $proofForToken->hash($secret);
        $tokens1 = [
            new Document([
                '$id' => Id::custom('token1'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 60 * 60 * 24)),
                'secret' => $hash,
            ]),
            new Document([
                '$id' => Id::custom('token2'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                'secret' => 'secret2',
            ]),
        ];

        $tokens2 = [
            new Document([ // Correct secret and type time, wrong expire time
                '$id' => Id::custom('token1'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                'secret' => $hash,
            ]),
            new Document([
                '$id' => Id::custom('token2'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                'secret' => 'secret2',
            ]),
        ];

        $tokens3 = [ // Correct secret and expire time, wrong type
            new Document([
                '$id' => Id::custom('token1'),
                'type' => TOKEN_TYPE_INVITE,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 60 * 60 * 24)),
                'secret' => $hash,
            ]),
            new Document([
                '$id' => Id::custom('token2'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                'secret' => 'secret2',
            ]),
        ];

        $user1 = new User([
            '$id' => Id::custom('user1'),
            'tokens' => $tokens1,
        ]);

        $user2 = new User([
            '$id' => Id::custom('user2'),
            'tokens' => $tokens2,
        ]);

        $user3 = new User([
            '$id' => Id::custom('user3'),
            'tokens' => $tokens3,
        ]);

        $this->assertEquals($user1->tokenVerify(TOKEN_TYPE_RECOVERY, $secret, $proofForToken), $tokens1[0]);
        $this->assertEquals($user1->tokenVerify(null, $secret, $proofForToken), $tokens1[0]);
        $this->assertEquals(false, $user1->tokenVerify(TOKEN_TYPE_RECOVERY, 'false-secret', $proofForToken));
        $this->assertEquals(false, $user2->tokenVerify(TOKEN_TYPE_RECOVERY, $secret, $proofForToken));
        $this->assertEquals(false, $user2->tokenVerify(TOKEN_TYPE_RECOVERY, 'false-secret', $proofForToken));
        $this->assertEquals(false, $user3->tokenVerify(TOKEN_TYPE_RECOVERY, $secret, $proofForToken));
        $this->assertEquals(false, $user3->tokenVerify(TOKEN_TYPE_RECOVERY, 'false-secret', $proofForToken));
    }

    public function testTokenVerifyVerificationOtp(): void
    {
        $proofForCode = new Code();
        $proofForToken = new Token();

        $code = $proofForCode->generate();
        $token = $proofForToken->generate();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 60 * 60 * 24));

        $user = new User([
            '$id' => Id::custom('user1'),
            'tokens' => [
                new Document([
                    '$id' => Id::custom('otp'),
                    'type' => TOKEN_TYPE_VERIFICATION_OTP,
                    'expire' => $expire,
                    'secret' => $proofForCode->hash($code),
                ]),
                new Document([
                    '$id' => Id::custom('link'),
                    'type' => TOKEN_TYPE_VERIFICATION,
                    'expire' => $expire,
                    'secret' => $proofForToken->hash($token),
                ]),
            ],
        ]);

        $this->assertSame('otp', $user->tokenVerify(TOKEN_TYPE_VERIFICATION_OTP, $code, $proofForCode)->getId());
        $this->assertSame('link', $user->tokenVerify(TOKEN_TYPE_VERIFICATION, $token, $proofForToken)->getId());

        $this->assertEquals(false, $user->tokenVerify(TOKEN_TYPE_VERIFICATION, $code, $proofForToken));
        $this->assertEquals(false, $user->tokenVerify(TOKEN_TYPE_VERIFICATION_OTP, $token, $proofForCode));

        $expired = new User([
            '$id' => Id::custom('user2'),
            'tokens' => [
                new Document([
                    '$id' => Id::custom('otp'),
                    'type' => TOKEN_TYPE_VERIFICATION_OTP,
                    'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                    'secret' => $proofForCode->hash($code),
                ]),
            ],
        ]);

        $this->assertEquals(false, $expired->tokenVerify(TOKEN_TYPE_VERIFICATION_OTP, $code, $proofForCode));
    }

    public function testTokenVerifyRecoveryOtp(): void
    {
        $proofForCode = new Code();
        $proofForToken = new Token();

        $code = $proofForCode->generate();
        $token = $proofForToken->generate();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 60 * 60 * 24));

        $user = new User([
            '$id' => Id::custom('user1'),
            'tokens' => [
                new Document([
                    '$id' => Id::custom('otp'),
                    'type' => TOKEN_TYPE_RECOVERY_OTP,
                    'expire' => $expire,
                    'secret' => $proofForCode->hash($code),
                ]),
                new Document([
                    '$id' => Id::custom('link'),
                    'type' => TOKEN_TYPE_RECOVERY,
                    'expire' => $expire,
                    'secret' => $proofForToken->hash($token),
                ]),
            ],
        ]);

        $this->assertSame('otp', $user->tokenVerify(TOKEN_TYPE_RECOVERY_OTP, $code, $proofForCode)->getId());
        $this->assertSame('link', $user->tokenVerify(TOKEN_TYPE_RECOVERY, $token, $proofForToken)->getId());

        $this->assertEquals(false, $user->tokenVerify(TOKEN_TYPE_RECOVERY, $code, $proofForToken));
        $this->assertEquals(false, $user->tokenVerify(TOKEN_TYPE_RECOVERY_OTP, $token, $proofForCode));

        $expired = new User([
            '$id' => Id::custom('user2'),
            'tokens' => [
                new Document([
                    '$id' => Id::custom('otp'),
                    'type' => TOKEN_TYPE_RECOVERY_OTP,
                    'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                    'secret' => $proofForCode->hash($code),
                ]),
            ],
        ]);

        $this->assertEquals(false, $expired->tokenVerify(TOKEN_TYPE_RECOVERY_OTP, $code, $proofForCode));
    }

    public function testIsPrivilegedUser(): void
    {
        $user = new User();

        $this->assertEquals(false, $user->isPrivileged([]));
        $this->assertEquals(false, $user->isPrivileged([Role::guests()->toString()]));
        $this->assertEquals(false, $user->isPrivileged([Role::users()->toString()]));
        $this->assertEquals(true, $user->isPrivileged([User::ROLE_ADMIN]));
        $this->assertEquals(true, $user->isPrivileged([User::ROLE_DEVELOPER]));
        $this->assertEquals(true, $user->isPrivileged([User::ROLE_OWNER]));
        $this->assertEquals(false, $user->isPrivileged([User::ROLE_KEYS]));
        $this->assertEquals(false, $user->isPrivileged([User::ROLE_SYSTEM]));

        $this->assertEquals(false, $user->isPrivileged([User::ROLE_KEYS, User::ROLE_KEYS]));
        $this->assertEquals(false, $user->isPrivileged([User::ROLE_KEYS, Role::guests()->toString()]));
        $this->assertEquals(true, $user->isPrivileged([User::ROLE_OWNER, Role::guests()->toString()]));
        $this->assertEquals(true, $user->isPrivileged([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_DEVELOPER]));
    }

    public function testIsAppUser(): void
    {
        $user = new User();

        $this->assertEquals(false, $user->isKey([]));
        $this->assertEquals(false, $user->isKey([Role::guests()->toString()]));
        $this->assertEquals(false, $user->isKey([Role::users()->toString()]));
        $this->assertEquals(false, $user->isKey([User::ROLE_ADMIN]));
        $this->assertEquals(false, $user->isKey([User::ROLE_DEVELOPER]));
        $this->assertEquals(false, $user->isKey([User::ROLE_OWNER]));
        $this->assertEquals(true, $user->isKey([User::ROLE_KEYS]));
        $this->assertEquals(false, $user->isKey([User::ROLE_SYSTEM]));

        $this->assertEquals(true, $user->isKey([User::ROLE_KEYS, User::ROLE_KEYS]));
        $this->assertEquals(true, $user->isKey([User::ROLE_KEYS, Role::guests()->toString()]));
        $this->assertEquals(false, $user->isKey([User::ROLE_OWNER, Role::guests()->toString()]));
        $this->assertEquals(false, $user->isKey([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_DEVELOPER]));
    }

    public function testGuestRoles(): void
    {
        $user = new User([
            '$id' => ''
        ]);

        $roles = $user->getRoles($this->getAuthorization());
        $this->assertCount(1, $roles);
        $this->assertContains(Role::guests()->toString(), $roles);
    }

    public function testUserRoles(): void
    {
        $user  = new User([
            '$id' => Id::custom('123'),
            'labels' => [
                'vip',
                'admin'
            ],
            'emailVerification' => true,
            'phoneVerification' => true,
            'memberships' => [
                [
                    '$id' => Id::custom('456'),
                    'teamId' => Id::custom('abc'),
                    'confirm' => true,
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    '$id' => Id::custom('abc'),
                    'teamId' => Id::custom('def'),
                    'confirm' => true,
                    'roles' => [
                        'guest'
                    ]
                ]
            ]
        ]);

        $roles = $user->getRoles($this->getAuthorization());

        $this->assertCount(13, $roles);
        $this->assertContains(Role::users()->toString(), $roles);
        $this->assertContains(Role::user(Id::custom('123'))->toString(), $roles);
        $this->assertContains(Role::users(Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::user(Id::custom('123'), Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('abc'))->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('abc'), 'administrator')->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('abc'), 'moderator')->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('def'))->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('def'), 'guest')->toString(), $roles);
        $this->assertContains(Role::member(Id::custom('456'))->toString(), $roles);
        $this->assertContains(Role::member(Id::custom('abc'))->toString(), $roles);
        $this->assertContains('label:vip', $roles);
        $this->assertContains('label:admin', $roles);

        // Disable all verification
        $user['emailVerification'] = false;
        $user['phoneVerification'] = false;

        $roles = $user->getRoles($this->getAuthorization());
        $this->assertContains(Role::users(Roles::DIMENSION_UNVERIFIED)->toString(), $roles);
        $this->assertContains(Role::user(Id::custom('123'), Roles::DIMENSION_UNVERIFIED)->toString(), $roles);

        // Enable single verification type
        $user['emailVerification'] = true;

        $roles = $user->getRoles($this->getAuthorization());
        $this->assertContains(Role::users(Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::user(Id::custom('123'), Roles::DIMENSION_VERIFIED)->toString(), $roles);
    }

    public function testPrivilegedUserRoles(): void
    {
        $this->getAuthorization()->addRole(User::ROLE_OWNER);
        $user  = new User([
            '$id' => Id::custom('123'),
            'emailVerification' => true,
            'phoneVerification' => true,
            'memberships' => [
                [
                    '$id' => Id::custom('def'),
                    'teamId' => Id::custom('abc'),
                    'confirm' => true,
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    '$id' => Id::custom('abc'),
                    'teamId' => Id::custom('def'),
                    'confirm' => true,
                    'roles' => [
                        'guest'
                    ]
                ]
            ]
        ]);
        $roles = $user->getRoles($this->getAuthorization());

        $this->assertCount(11, $roles);
        $this->assertContains(Role::users()->toString(), $roles);
        $this->assertContains(Role::user(Id::custom('123'))->toString(), $roles);
        $this->assertContains(Role::users(Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::user(Id::custom('123'), Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('abc'))->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('abc'), 'administrator')->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('abc'), 'moderator')->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('def'))->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('def'), 'guest')->toString(), $roles);
        $this->assertContains(Role::member(Id::custom('def'))->toString(), $roles);
        $this->assertContains(Role::member(Id::custom('abc'))->toString(), $roles);
    }

    public function testAppUserRoles(): void
    {
        $this->getAuthorization()->addRole(User::ROLE_KEYS);
        $user  = new User([
            '$id' => Id::custom('123'),
            'memberships' => [
                [
                    '$id' => Id::custom('def'),
                    'teamId' => Id::custom('abc'),
                    'confirm' => true,
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    '$id' => Id::custom('abc'),
                    'teamId' => Id::custom('def'),
                    'confirm' => true,
                    'roles' => [
                        'guest'
                    ]
                ]
            ]
        ]);

        $roles = $user->getRoles($this->getAuthorization());

        $this->assertCount(7, $roles);
        $this->assertNotContains(Role::users()->toString(), $roles);
        $this->assertNotContains(Role::user(Id::custom('123'))->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('abc'))->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('abc'), 'administrator')->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('abc'), 'moderator')->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('def'))->toString(), $roles);
        $this->assertContains(Role::team(Id::custom('def'), 'guest')->toString(), $roles);
        $this->assertContains(Role::member(Id::custom('def'))->toString(), $roles);
        $this->assertContains(Role::member(Id::custom('abc'))->toString(), $roles);
    }

    /**
     * An `identities` collection on an in-memory database, holding `$existing` already.
     *
     * @param  list<Document>  $existing
     */
    private function identities(array $existing = []): RacingDatabase
    {
        $authorization = new Authorization();
        $authorization->disable();

        $database = (new RacingDatabase(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_identities')
            ->setAuthorization($authorization);

        $database->create();

        $collection = Config::getParam('collections', [])['projects']['identities'];
        $database->createCollection(Collection::create(
            id: 'identities',
            attributes: $collection['attributes'],
            indexes: $collection['indexes'],
        ));

        foreach ($existing as $identity) {
            $database->createDocument('identities', $identity);
        }

        return $database;
    }

    private function identity(string $userId, string $sequence, string $provider, string $providerUid, string $providerEmail): Document
    {
        return new Document([
            '$id' => Id::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ],
            'userInternalId' => $sequence,
            'userId' => $userId,
            'provider' => $provider,
            'providerUid' => $providerUid,
            'providerEmail' => $providerEmail,
        ]);
    }

    /** @return list<string> the userIds whose identities hold $providerEmail */
    private function holders(Database $database, string $providerEmail): array
    {
        $identities = $database->find('identities', [
            Query::equal('providerEmail', [$providerEmail]),
        ]);

        return \array_values(\array_unique(\array_map(
            fn (Document $identity): string => $identity->getAttribute('userId'),
            $identities,
        )));
    }

    public function testLinkIdentityRefusesAnEmailAnotherUserHolds(): void
    {
        $database = $this->identities([
            $this->identity('alice', '1', 'google', 'google-alice', 'shared@example.com'),
        ]);

        $linked = User::linkIdentity($database, $this->identity('bob', '2', 'github', 'github-bob', 'shared@example.com'));

        $this->assertFalse($linked);
        $this->assertSame(['alice'], $this->holders($database, 'shared@example.com'));
    }

    public function testLinkIdentityRefusesAnEmailTakenSinceItWasChecked(): void
    {
        $database = $this->identities();

        // Alice links the same verified address while Bob's link is in flight: her insert
        // commits after Bob's own check came up clean and after Bob's row went in.
        $database->interleave('identities', 1, function () use ($database): void {
            $database->createDocument('identities', $this->identity('alice', '1', 'google', 'google-alice', 'shared@example.com'));
        });

        $linked = User::linkIdentity($database, $this->identity('bob', '2', 'github', 'github-bob', 'shared@example.com'));

        $this->assertFalse($linked, 'the link that lost the race is refused');
        $this->assertSame(['alice'], $this->holders($database, 'shared@example.com'), 'one user holds the email');
        $this->assertCount(1, $database->find('identities'), 'the refused link left no identity behind');
    }

    public function testLinkIdentityKeepsSeveralIdentitiesOfOneUserOnOneEmail(): void
    {
        $database = $this->identities([
            $this->identity('alice', '1', 'google', 'google-alice', 'shared@example.com'),
        ]);

        $linked = User::linkIdentity($database, $this->identity('alice', '1', 'github', 'github-alice', 'shared@example.com'));

        $this->assertTrue($linked);
        $this->assertSame(['alice'], $this->holders($database, 'shared@example.com'));
        $this->assertCount(2, $database->find('identities', [
            Query::equal('providerEmail', ['shared@example.com']),
        ]));
    }

    public function testLinkIdentityWithoutAProviderEmailIsNeverRefused(): void
    {
        $database = $this->identities([
            $this->identity('alice', '1', 'google', 'google-alice', ''),
        ]);

        $linked = User::linkIdentity($database, $this->identity('bob', '2', 'github', 'github-bob', ''));

        $this->assertTrue($linked);
        $this->assertCount(2, $database->find('identities'));
    }

    public function testClaimRevokesEveryOtherWayIn(): void
    {
        $database = $this->createProjectDatabase();
        $user = $this->createRegistrant($database);

        $claimed = User::claim($database, $user, new Document(['$id' => 'project']));

        $this->assertTrue($claimed);
        $stored = $database->getDocument('users', $user->getId());
        $this->assertTrue($stored->getAttribute('emailVerification'));
        $this->assertNull($stored->getAttribute('password'));
        $this->assertTrue($user->getAttribute('emailVerification'));
        $this->assertEmpty($database->find('sessions'));
        $this->assertEmpty($database->find('tokens'));
    }

    public function testClaimSkipsAnAddressProvenSinceItWasRead(): void
    {
        $database = $this->createProjectDatabase();
        $project = new Document(['$id' => 'project']);
        $registrant = $this->createRegistrant($database);

        // The copy a second claim read before the first one took the user lock
        $stale = new User($registrant->getArrayCopy());

        // The first claim takes the lock, revokes the registrant's credentials and proves the address
        $this->assertTrue(User::claim($database, $registrant, $project));

        // Its own session lands after the revocation
        $database->createDocument('sessions', new Document([
            '$id' => Id::custom('claimant'),
            'userId' => $registrant->getId(),
            'userInternalId' => $registrant->getSequence(),
            'provider' => SESSION_PROVIDER_EMAIL,
            'providerUid' => 'registrant@example.com',
            'secret' => 'claimant-secret',
            'expire' => DateTime::addSeconds(new \DateTime(), 60 * 60),
        ]));

        $claimed = User::claim($database, $stale, $project);

        $this->assertFalse($claimed);
        $this->assertTrue($stale->getAttribute('emailVerification'));
        $this->assertCount(1, $database->find('sessions'));
        $this->assertSame('claimant', $database->find('sessions')[0]->getId());

        // The copy the caller goes on to persist as a whole document must not bring the revoked password back
        $this->assertEmpty($stale->getAttribute('password'));
        $database->updateDocument('users', $stale->getId(), $stale);
        $this->assertEmpty($database->getDocument('users', $stale->getId())->getAttribute('password'));
    }

    public function testConfirmIdentityKeepsALinkNobodyElseHolds(): void
    {
        $database = $this->identities();
        $linked = $database->createDocument('identities', $this->identity('bob', '2', 'github', 'github-bob', 'shared@example.com'));

        $this->assertTrue(User::confirmIdentity($database, $linked));
        $this->assertSame(['bob'], $this->holders($database, 'shared@example.com'));
    }

    public function testConfirmIdentityDropsALinkCommittedAlongsideAnotherUsers(): void
    {
        // Bob's link went in inside a transaction whose own check could not see Alice's, still uncommitted
        $database = $this->identities();
        $linked = $database->createDocument('identities', $this->identity('bob', '2', 'github', 'github-bob', 'shared@example.com'));
        $database->createDocument('identities', $this->identity('alice', '1', 'google', 'google-alice', 'shared@example.com'));

        $this->assertFalse(User::confirmIdentity($database, $linked));
        $this->assertSame(['alice'], $this->holders($database, 'shared@example.com'));
    }

    public function testLockUnchangedHoldsForAnAccountAsTheRequestFoundIt(): void
    {
        $database = $this->createProjectDatabase();
        $registrant = $this->createRegistrant($database);
        $snapshot = new Document($registrant->getArrayCopy());

        $unchanged = $database->withTransaction(fn () => User::lockUnchanged($database, $snapshot, ['sessions' => 'registrant-session']));

        $this->assertTrue($unchanged);
    }

    public function testLockUnchangedRefusesARequestThatReadTheAccountBeforeItWasClaimed(): void
    {
        $database = $this->createProjectDatabase();
        $registrant = $this->createRegistrant($database);

        // The registrant's sign-in read the account, and resolved it through their session, before the owner claimed it
        $snapshot = new Document($registrant->getArrayCopy());
        $this->assertTrue(User::claim($database, $registrant, new Document(['$id' => 'project'])));

        $this->assertFalse($database->withTransaction(fn () => User::lockUnchanged($database, $snapshot, ['sessions' => 'registrant-session'])));

        // The flipped verification alone gives the claim away, whatever the request got in through
        $this->assertFalse($database->withTransaction(fn () => User::lockUnchanged($database, $snapshot, [])));
    }

    public function testLockUnchangedRefusesARequestWhoseWayInIsGone(): void
    {
        $database = $this->createProjectDatabase();
        $registrant = $this->createRegistrant($database);
        $snapshot = new Document($registrant->getArrayCopy());

        $database->deleteDocument('sessions', 'registrant-session');

        $this->assertFalse($database->withTransaction(fn () => User::lockUnchanged($database, $snapshot, ['sessions' => 'registrant-session'])));
    }

    public function testASignInRefusedUnderTheLockLeavesTheAddressFree(): void
    {
        $database = $this->createProjectDatabase();
        $anonymous = $this->createAnonymous($database);
        $snapshot = new Document($anonymous->getArrayCopy());

        // The session the request resolved the account through is gone before the sign-in takes the lock
        $database->deleteDocument('sessions', 'anonymous-session');

        $database->withTransaction(function () use ($database, $snapshot, $anonymous) {
            if (!User::lockUnchanged($database, $snapshot, ['sessions' => 'anonymous-session'])) {
                return;
            }

            User::reserveEmailTarget($database, $anonymous, 'linked@example.com');
        });

        $this->assertTrue($database->findOne('targets', [Query::equal('identifier', ['linked@example.com'])])->isEmpty());
    }

    public function testReserveEmailTargetReservesAFreeAddressOnce(): void
    {
        $database = $this->createProjectDatabase();
        $anonymous = $this->createAnonymous($database);

        $target = User::reserveEmailTarget($database, $anonymous, 'linked@example.com');

        $this->assertNotNull($target);
        $this->assertSame($anonymous->getSequence(), $target->getAttribute('userInternalId'));
        $this->assertSame(MESSAGE_TYPE_EMAIL, $target->getAttribute('providerType'));

        // The account already holds it
        $this->assertNull(User::reserveEmailTarget($database, $anonymous, 'linked@example.com'));
        $this->assertCount(1, $database->find('targets', [Query::equal('identifier', ['linked@example.com'])]));
    }

    public function testReserveEmailTargetRollsBackASignInWhoseAddressAnotherUserHolds(): void
    {
        $database = $this->createProjectDatabase();
        $registrant = $this->createRegistrant($database);
        $database->createDocument('targets', new Document([
            'userId' => $registrant->getId(),
            'userInternalId' => $registrant->getSequence(),
            'providerType' => MESSAGE_TYPE_EMAIL,
            'identifier' => 'registrant@example.com',
        ]));
        $anonymous = $this->createAnonymous($database);

        try {
            $database->withTransaction(function () use ($database, $anonymous) {
                $database->updateDocument('users', $anonymous->getId(), new Document(['email' => 'registrant@example.com']));
                User::reserveEmailTarget($database, $anonymous, 'registrant@example.com');
            });
            $this->fail('An address another user holds was reserved.');
        } catch (Duplicate) {
        }

        $this->assertEmpty($database->getDocument('users', $anonymous->getId())->getAttribute('email'));
        $this->assertSame(['registrant'], \array_map(
            fn (Document $target) => $target->getAttribute('userId'),
            $database->find('targets', [Query::equal('identifier', ['registrant@example.com'])]),
        ));
    }

    public function testRevertSignInRestoresTheProfileAndReleasesTheAddress(): void
    {
        $database = $this->createProjectDatabase();
        $anonymous = $this->createAnonymous($database);
        $snapshot = new Document($anonymous->getArrayCopy());

        // A sign-in backfilled the provider's address, verified, then had its identity link refused after the commit
        $changes = ['status' => true, 'name' => 'Linked', 'email' => 'linked@example.com', 'emailVerification' => true];
        $target = $database->withTransaction(function () use ($database, $anonymous, $changes) {
            $target = User::reserveEmailTarget($database, $anonymous, 'linked@example.com');
            $database->updateDocument('users', $anonymous->getId(), new Document($changes));

            return $target;
        });

        $database->withTransaction(fn () => User::revertSignIn($database, $snapshot, $changes, $target));

        $reverted = $database->getDocument('users', $anonymous->getId());
        $this->assertEmpty($reverted->getAttribute('email'));
        $this->assertFalse($reverted->getAttribute('emailVerification'));
        $this->assertEmpty($reverted->getAttribute('name'));
        $this->assertTrue($reverted->getAttribute('status'));
        $this->assertTrue($database->findOne('targets', [Query::equal('identifier', ['linked@example.com'])])->isEmpty());
    }

    public function testRevertSignInKeepsWhatChangedSince(): void
    {
        $database = $this->createProjectDatabase();
        $anonymous = $this->createAnonymous($database);
        $snapshot = new Document($anonymous->getArrayCopy());

        $changes = ['name' => 'Linked', 'email' => 'linked@example.com', 'emailVerification' => true];
        $database->updateDocument('users', $anonymous->getId(), new Document($changes));

        // The owner renamed the account between the sign-in's commit and its undo
        $database->updateDocument('users', $anonymous->getId(), new Document(['name' => 'Renamed']));

        $database->withTransaction(fn () => User::revertSignIn($database, $snapshot, $changes, null));

        $reverted = $database->getDocument('users', $anonymous->getId());
        $this->assertSame('Renamed', $reverted->getAttribute('name'));
        $this->assertEmpty($reverted->getAttribute('email'));
        $this->assertFalse($reverted->getAttribute('emailVerification'));
    }

    private function createProjectDatabase(): Database
    {
        $authorization = new Authorization();
        $authorization->disable();

        $database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_1')
            ->setAuthorization($authorization);

        $database->create();

        $collections = Config::getParam('collections', [])['projects'];
        foreach (['users', 'sessions', 'tokens', 'challenges', 'authenticators', 'identities', 'memberships', 'targets', 'subscribers'] as $id) {
            $database->createCollection(Collection::create(
                id: $id,
                attributes: $collections[$id]['attributes'],
                indexes: $collections[$id]['indexes'],
            ));
        }

        return $database;
    }

    /**
     * An account registered with an email that was never verified, holding a password,
     * a session and a token.
     */
    private function createRegistrant(Database $database): User
    {
        $user = $database->createDocument('users', new Document([
            '$id' => Id::custom('registrant'),
            'email' => 'registrant@example.com',
            'emailVerification' => false,
            'password' => 'registrant-password-hash',
            'status' => true,
            'name' => 'Registrant',
        ]));

        $database->createDocument('sessions', new Document([
            '$id' => Id::custom('registrant-session'),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'provider' => SESSION_PROVIDER_EMAIL,
            'providerUid' => 'registrant@example.com',
            'secret' => 'registrant-secret',
            'expire' => DateTime::addSeconds(new \DateTime(), 60 * 60),
        ]));

        $database->createDocument('tokens', new Document([
            '$id' => Id::custom('registrant-token'),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_RECOVERY,
            'secret' => 'registrant-token-secret',
            'expire' => DateTime::addSeconds(new \DateTime(), 60 * 60),
        ]));

        return new User($database->getDocument('users', $user->getId())->getArrayCopy());
    }

    /**
     * An anonymous account with no email or name, holding a session.
     */
    private function createAnonymous(Database $database): User
    {
        $user = $database->createDocument('users', new Document([
            '$id' => Id::custom('anonymous'),
            'emailVerification' => false,
            'status' => true,
        ]));

        $database->createDocument('sessions', new Document([
            '$id' => Id::custom('anonymous-session'),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'provider' => SESSION_PROVIDER_ANONYMOUS,
            'providerUid' => '',
            'secret' => 'anonymous-secret',
            'expire' => DateTime::addSeconds(new \DateTime(), 60 * 60),
        ]));

        return new User($database->getDocument('users', $user->getId())->getArrayCopy());
    }
}
