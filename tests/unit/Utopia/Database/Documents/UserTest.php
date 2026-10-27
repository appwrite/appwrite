<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Documents;

use Appwrite\Auth\MFA\Type;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Proofs\Code;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Roles;

final class UserTest extends TestCase
{
    private $authorization;

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
}
