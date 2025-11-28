<?php

namespace Tests\Unit\Utopia\Database\Documents;

use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Roles;

class UserTest extends TestCase
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
                '$id' => ID::custom('token1'),
                'secret' => $hash,
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
                'expire' => DateTime::addSeconds(new \DateTime(), $expireTime1),
            ]),
            new Document([
                '$id' => ID::custom('token2'),
                'secret' => 'secret2',
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
                'expire' => DateTime::addSeconds(new \DateTime(), $expireTime1),
            ]),
        ];

        $expireTime2 = -60 * 60 * 24;

        $tokens2 = [
            new Document([ // Correct secret and type time, wrong expire time
                '$id' => ID::custom('token1'),
                'secret' => $hash,
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
                'expire' => DateTime::addSeconds(new \DateTime(), $expireTime2),
            ]),
            new Document([
                '$id' => ID::custom('token2'),
                'secret' => 'secret2',
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
                'expire' => DateTime::addSeconds(new \DateTime(), $expireTime2),
            ]),
        ];

        $user1 = new User([
            '$id' => ID::custom('user1'),
            'sessions' => $tokens1,

        ]);

        $user2 = new User([
            '$id' => ID::custom('user2'),
            'sessions' => $tokens2,
        ]);

        $this->assertEquals('token1', $user1->sessionVerify($secret, $proofForToken));
        $this->assertEquals($user1->sessionVerify('false-secret', $proofForToken), false);
        $this->assertEquals($user2->sessionVerify($secret, $proofForToken), false);
        $this->assertEquals($user2->sessionVerify('false-secret', $proofForToken), false);
    }

    public function testTokenVerify(): void
    {
        $proofForToken = new Token();
        $secret = 'secret1';
        $hash = $proofForToken->hash($secret);
        $tokens1 = [
            new Document([
                '$id' => ID::custom('token1'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 60 * 60 * 24)),
                'secret' => $hash,
            ]),
            new Document([
                '$id' => ID::custom('token2'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                'secret' => 'secret2',
            ]),
        ];

        $tokens2 = [
            new Document([ // Correct secret and type time, wrong expire time
                '$id' => ID::custom('token1'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                'secret' => $hash,
            ]),
            new Document([
                '$id' => ID::custom('token2'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                'secret' => 'secret2',
            ]),
        ];

        $tokens3 = [ // Correct secret and expire time, wrong type
            new Document([
                '$id' => ID::custom('token1'),
                'type' => TOKEN_TYPE_INVITE,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 60 * 60 * 24)),
                'secret' => $hash,
            ]),
            new Document([
                '$id' => ID::custom('token2'),
                'type' => TOKEN_TYPE_RECOVERY,
                'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60 * 60 * 24)),
                'secret' => 'secret2',
            ]),
        ];

        $user1 = new User([
            '$id' => ID::custom('user1'),
            'tokens' => $tokens1,
        ]);

        $user2 = new User([
            '$id' => ID::custom('user2'),
            'tokens' => $tokens2,
        ]);

        $user3 = new User([
            '$id' => ID::custom('user3'),
            'tokens' => $tokens3,
        ]);

        $this->assertEquals($user1->tokenVerify(TOKEN_TYPE_RECOVERY, $secret, $proofForToken), $tokens1[0]);
        $this->assertEquals($user1->tokenVerify(null, $secret, $proofForToken), $tokens1[0]);
        $this->assertEquals($user1->tokenVerify(TOKEN_TYPE_RECOVERY, 'false-secret', $proofForToken), false);
        $this->assertEquals($user2->tokenVerify(TOKEN_TYPE_RECOVERY, $secret, $proofForToken), false);
        $this->assertEquals($user2->tokenVerify(TOKEN_TYPE_RECOVERY, 'false-secret', $proofForToken), false);
        $this->assertEquals($user3->tokenVerify(TOKEN_TYPE_RECOVERY, $secret, $proofForToken), false);
        $this->assertEquals($user3->tokenVerify(TOKEN_TYPE_RECOVERY, 'false-secret', $proofForToken), false);
    }

    public function testIsPrivilegedUser(): void
    {
        $this->assertEquals(false, User::isPrivileged([]));
        $this->assertEquals(false, User::isPrivileged([Role::guests()->toString()]));
        $this->assertEquals(false, User::isPrivileged([Role::users()->toString()]));
        $this->assertEquals(true, User::isPrivileged([User::ROLE_ADMIN]));
        $this->assertEquals(true, User::isPrivileged([User::ROLE_DEVELOPER]));
        $this->assertEquals(true, User::isPrivileged([User::ROLE_OWNER]));
        $this->assertEquals(false, User::isPrivileged([User::ROLE_APPS]));
        $this->assertEquals(false, User::isPrivileged([User::ROLE_SYSTEM]));

        $this->assertEquals(false, User::isPrivileged([User::ROLE_APPS, User::ROLE_APPS]));
        $this->assertEquals(false, User::isPrivileged([User::ROLE_APPS, Role::guests()->toString()]));
        $this->assertEquals(true, User::isPrivileged([User::ROLE_OWNER, Role::guests()->toString()]));
        $this->assertEquals(true, User::isPrivileged([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_DEVELOPER]));
    }

    public function testIsAppUser(): void
    {
        $this->assertEquals(false, User::isApp([]));
        $this->assertEquals(false, User::isApp([Role::guests()->toString()]));
        $this->assertEquals(false, User::isApp([Role::users()->toString()]));
        $this->assertEquals(false, User::isApp([User::ROLE_ADMIN]));
        $this->assertEquals(false, User::isApp([User::ROLE_DEVELOPER]));
        $this->assertEquals(false, User::isApp([User::ROLE_OWNER]));
        $this->assertEquals(true, User::isApp([User::ROLE_APPS]));
        $this->assertEquals(false, User::isApp([User::ROLE_SYSTEM]));

        $this->assertEquals(true, User::isApp([User::ROLE_APPS, User::ROLE_APPS]));
        $this->assertEquals(true, User::isApp([User::ROLE_APPS, Role::guests()->toString()]));
        $this->assertEquals(false, User::isApp([User::ROLE_OWNER, Role::guests()->toString()]));
        $this->assertEquals(false, User::isApp([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_DEVELOPER]));
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
            '$id' => ID::custom('123'),
            'labels' => [
                'vip',
                'admin'
            ],
            'emailVerification' => true,
            'phoneVerification' => true,
            'memberships' => [
                [
                    '$id' => ID::custom('456'),
                    'teamId' => ID::custom('abc'),
                    'confirm' => true,
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    '$id' => ID::custom('abc'),
                    'teamId' => ID::custom('def'),
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
        $this->assertContains(Role::user(ID::custom('123'))->toString(), $roles);
        $this->assertContains(Role::users(Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::user(ID::custom('123'), Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('abc'))->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('abc'), 'administrator')->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('abc'), 'moderator')->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('def'))->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('def'), 'guest')->toString(), $roles);
        $this->assertContains(Role::member(ID::custom('456'))->toString(), $roles);
        $this->assertContains(Role::member(ID::custom('abc'))->toString(), $roles);
        $this->assertContains('label:vip', $roles);
        $this->assertContains('label:admin', $roles);

        // Disable all verification
        $user['emailVerification'] = false;
        $user['phoneVerification'] = false;

        $roles = $user->getRoles($this->getAuthorization());
        $this->assertContains(Role::users(Roles::DIMENSION_UNVERIFIED)->toString(), $roles);
        $this->assertContains(Role::user(ID::custom('123'), Roles::DIMENSION_UNVERIFIED)->toString(), $roles);

        // Enable single verification type
        $user['emailVerification'] = true;

        $roles = $user->getRoles($this->getAuthorization());
        $this->assertContains(Role::users(Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::user(ID::custom('123'), Roles::DIMENSION_VERIFIED)->toString(), $roles);
    }

    public function testPrivilegedUserRoles(): void
    {
        $this->getAuthorization()->addRole(User::ROLE_OWNER);
        $user  = new User([
            '$id' => ID::custom('123'),
            'emailVerification' => true,
            'phoneVerification' => true,
            'memberships' => [
                [
                    '$id' => ID::custom('def'),
                    'teamId' => ID::custom('abc'),
                    'confirm' => true,
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    '$id' => ID::custom('abc'),
                    'teamId' => ID::custom('def'),
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
        $this->assertNotContains(Role::user(ID::custom('123'))->toString(), $roles);
        $this->assertNotContains(Role::users(Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertNotContains(Role::user(ID::custom('123'), Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('abc'))->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('abc'), 'administrator')->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('abc'), 'moderator')->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('def'))->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('def'), 'guest')->toString(), $roles);
        $this->assertContains(Role::member(ID::custom('def'))->toString(), $roles);
        $this->assertContains(Role::member(ID::custom('abc'))->toString(), $roles);
    }

    public function testAppUserRoles(): void
    {
        $this->getAuthorization()->addRole(User::ROLE_APPS);
        $user  = new User([
            '$id' => ID::custom('123'),
            'memberships' => [
                [
                    '$id' => ID::custom('def'),
                    'teamId' => ID::custom('abc'),
                    'confirm' => true,
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    '$id' => ID::custom('abc'),
                    'teamId' => ID::custom('def'),
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
        $this->assertNotContains(Role::user(ID::custom('123'))->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('abc'))->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('abc'), 'administrator')->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('abc'), 'moderator')->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('def'))->toString(), $roles);
        $this->assertContains(Role::team(ID::custom('def'), 'guest')->toString(), $roles);
        $this->assertContains(Role::member(ID::custom('def'))->toString(), $roles);
        $this->assertContains(Role::member(ID::custom('abc'))->toString(), $roles);
    }
}
