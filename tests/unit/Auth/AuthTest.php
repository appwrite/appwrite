<?php

namespace Tests\Unit\Auth;

use Appwrite\Auth\Auth;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Roles;

class AuthTest extends TestCase
{
    /**
     * Reset Roles
     */
    public function tearDown(): void
    {
        Authorization::cleanRoles();
        Authorization::setRole(Role::any()->toString());
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

        $this->assertEquals(Auth::sessionVerify($tokens1, $secret, $proofForToken), 'token1');
        $this->assertEquals(Auth::sessionVerify($tokens1, 'false-secret', $proofForToken), false);
        $this->assertEquals(Auth::sessionVerify($tokens2, $secret, $proofForToken), false);
        $this->assertEquals(Auth::sessionVerify($tokens2, 'false-secret', $proofForToken), false);
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

        $this->assertEquals(Auth::tokenVerify($tokens1, TOKEN_TYPE_RECOVERY, $secret, $proofForToken), $tokens1[0]);
        $this->assertEquals(Auth::tokenVerify($tokens1, null, $secret, $proofForToken), $tokens1[0]);
        $this->assertEquals(Auth::tokenVerify($tokens1, TOKEN_TYPE_RECOVERY, 'false-secret', $proofForToken), false);
        $this->assertEquals(Auth::tokenVerify($tokens2, TOKEN_TYPE_RECOVERY, $secret, $proofForToken), false);
        $this->assertEquals(Auth::tokenVerify($tokens2, TOKEN_TYPE_RECOVERY, 'false-secret', $proofForToken), false);
        $this->assertEquals(Auth::tokenVerify($tokens3, TOKEN_TYPE_RECOVERY, $secret, $proofForToken), false);
        $this->assertEquals(Auth::tokenVerify($tokens3, TOKEN_TYPE_RECOVERY, 'false-secret', $proofForToken), false);
    }

    public function testIsPrivilegedUser(): void
    {
        $this->assertEquals(false, Auth::isPrivilegedUser([]));
        $this->assertEquals(false, Auth::isPrivilegedUser([Role::guests()->toString()]));
        $this->assertEquals(false, Auth::isPrivilegedUser([Role::users()->toString()]));
        $this->assertEquals(true, Auth::isPrivilegedUser([USER_ROLE_ADMIN]));
        $this->assertEquals(true, Auth::isPrivilegedUser([USER_ROLE_DEVELOPER]));
        $this->assertEquals(true, Auth::isPrivilegedUser([USER_ROLE_OWNER]));
        $this->assertEquals(false, Auth::isPrivilegedUser([USER_ROLE_APPS]));
        $this->assertEquals(false, Auth::isPrivilegedUser([USER_ROLE_SYSTEM]));

        $this->assertEquals(false, Auth::isPrivilegedUser([USER_ROLE_APPS, USER_ROLE_APPS]));
        $this->assertEquals(false, Auth::isPrivilegedUser([USER_ROLE_APPS, Role::guests()->toString()]));
        $this->assertEquals(true, Auth::isPrivilegedUser([USER_ROLE_OWNER, Role::guests()->toString()]));
        $this->assertEquals(true, Auth::isPrivilegedUser([USER_ROLE_OWNER, USER_ROLE_ADMIN, USER_ROLE_DEVELOPER]));
    }

    public function testIsAppUser(): void
    {
        $this->assertEquals(false, Auth::isAppUser([]));
        $this->assertEquals(false, Auth::isAppUser([Role::guests()->toString()]));
        $this->assertEquals(false, Auth::isAppUser([Role::users()->toString()]));
        $this->assertEquals(false, Auth::isAppUser([USER_ROLE_ADMIN]));
        $this->assertEquals(false, Auth::isAppUser([USER_ROLE_DEVELOPER]));
        $this->assertEquals(false, Auth::isAppUser([USER_ROLE_OWNER]));
        $this->assertEquals(true, Auth::isAppUser([USER_ROLE_APPS]));
        $this->assertEquals(false, Auth::isAppUser([USER_ROLE_SYSTEM]));

        $this->assertEquals(true, Auth::isAppUser([USER_ROLE_APPS, USER_ROLE_APPS]));
        $this->assertEquals(true, Auth::isAppUser([USER_ROLE_APPS, Role::guests()->toString()]));
        $this->assertEquals(false, Auth::isAppUser([USER_ROLE_OWNER, Role::guests()->toString()]));
        $this->assertEquals(false, Auth::isAppUser([USER_ROLE_OWNER, USER_ROLE_ADMIN, USER_ROLE_DEVELOPER]));
    }

    public function testGuestRoles(): void
    {
        $user = new Document([
            '$id' => ''
        ]);

        $roles = Auth::getRoles($user);
        $this->assertCount(1, $roles);
        $this->assertContains(Role::guests()->toString(), $roles);
    }

    public function testUserRoles(): void
    {
        $user  = new Document([
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

        $roles = Auth::getRoles($user);

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

        $roles = Auth::getRoles($user);
        $this->assertContains(Role::users(Roles::DIMENSION_UNVERIFIED)->toString(), $roles);
        $this->assertContains(Role::user(ID::custom('123'), Roles::DIMENSION_UNVERIFIED)->toString(), $roles);

        // Enable single verification type
        $user['emailVerification'] = true;

        $roles = Auth::getRoles($user);
        $this->assertContains(Role::users(Roles::DIMENSION_VERIFIED)->toString(), $roles);
        $this->assertContains(Role::user(ID::custom('123'), Roles::DIMENSION_VERIFIED)->toString(), $roles);
    }

    public function testPrivilegedUserRoles(): void
    {
        Authorization::setRole(USER_ROLE_OWNER);
        $user  = new Document([
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

        $roles = Auth::getRoles($user);

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
        Authorization::setRole(USER_ROLE_APPS);
        $user  = new Document([
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

        $roles = Auth::getRoles($user);

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
