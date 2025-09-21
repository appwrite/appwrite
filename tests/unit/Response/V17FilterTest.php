<?php

namespace Tests\Unit\Response;

use Appwrite\Utopia\Response\Filters\V17;
use PHPUnit\Framework\TestCase;

class V17FilterTest extends TestCase
{
    private V17 $filter;

    protected function setUp(): void
    {
        $this->filter = new V17();
    }

    public function testParseUserWithArgon2HashOptions(): void
    {
        $content = [
            '$id' => 'user123',
            'email' => 'test@example.com',
            'hash' => 'argon2',
            'hashOptions' => [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3
            ],
            'targets' => ['should_be_removed'],
            'mfa' => ['should_be_removed']
        ];

        $result = $this->filter->parse($content, 'user');

        // Check that targets and mfa are removed
        $this->assertArrayNotHasKey('targets', $result);
        $this->assertArrayNotHasKey('mfa', $result);

        // Check that hashOptions are converted to API format
        $this->assertArrayHasKey('hashOptions', $result);
        $this->assertEquals('argon2', $result['hashOptions']['type']);
        $this->assertEquals(65536, $result['hashOptions']['memoryCost']);
        $this->assertEquals(4, $result['hashOptions']['timeCost']);
        $this->assertEquals(3, $result['hashOptions']['threads']);
    }

    public function testParseUserWithOldFormatHashOptions(): void
    {
        $content = [
            '$id' => 'user123',
            'email' => 'test@example.com',
            'hash' => 'argon2',
            'hashOptions' => [
                'type' => 'argon2',
                'memoryCost' => 2048,
                'timeCost' => 4,
                'threads' => 3
            ]
        ];

        $result = $this->filter->parse($content, 'user');

        // Check that old format is converted to API format
        $this->assertArrayHasKey('hashOptions', $result);
        $this->assertEquals('argon2', $result['hashOptions']['type']);
        $this->assertEquals(2048, $result['hashOptions']['memoryCost']);
        $this->assertEquals(4, $result['hashOptions']['timeCost']);
        $this->assertEquals(3, $result['hashOptions']['threads']);
    }

    public function testParseUserWithMixedFormatHashOptions(): void
    {
        $content = [
            '$id' => 'user123',
            'email' => 'test@example.com',
            'hash' => 'argon2',
            'hashOptions' => [
                'memory_cost' => 65536,
                'timeCost' => 4,
                'threads' => 3
            ]
        ];

        $result = $this->filter->parse($content, 'user');

        // Check that mixed format is handled correctly
        $this->assertArrayHasKey('hashOptions', $result);
        $this->assertEquals('argon2', $result['hashOptions']['type']);
        $this->assertEquals(65536, $result['hashOptions']['memoryCost']);
        $this->assertEquals(4, $result['hashOptions']['timeCost']);
        $this->assertEquals(3, $result['hashOptions']['threads']);
    }

    public function testParseUserWithNonArgon2Hash(): void
    {
        $content = [
            '$id' => 'user123',
            'email' => 'test@example.com',
            'hash' => 'bcrypt',
            'hashOptions' => [
                'cost' => 8
            ]
        ];

        $result = $this->filter->parse($content, 'user');

        // Non-Argon2 hashes should not be modified
        $this->assertArrayHasKey('hashOptions', $result);
        $this->assertEquals(['cost' => 8], $result['hashOptions']);
    }

    public function testParseUserWithoutHashOptions(): void
    {
        $content = [
            '$id' => 'user123',
            'email' => 'test@example.com',
            'hash' => 'argon2'
        ];

        $result = $this->filter->parse($content, 'user');

        // Should not add hashOptions if they don't exist
        $this->assertArrayNotHasKey('hashOptions', $result);
    }

    public function testParseUserWithInvalidHashOptions(): void
    {
        $content = [
            '$id' => 'user123',
            'email' => 'test@example.com',
            'hash' => 'argon2',
            'hashOptions' => 'invalid_string'
        ];

        $result = $this->filter->parse($content, 'user');

        // Invalid hashOptions should not be modified
        $this->assertEquals('invalid_string', $result['hashOptions']);
    }

    public function testParseUserWithEmptyHashOptions(): void
    {
        $content = [
            '$id' => 'user123',
            'email' => 'test@example.com',
            'hash' => 'argon2',
            'hashOptions' => []
        ];

        $result = $this->filter->parse($content, 'user');

        // Empty hashOptions should be converted to API format with defaults
        $this->assertArrayHasKey('hashOptions', $result);
        $this->assertEquals('argon2', $result['hashOptions']['type']);
        $this->assertEquals(65536, $result['hashOptions']['memoryCost']);
        $this->assertEquals(4, $result['hashOptions']['timeCost']);
        $this->assertEquals(3, $result['hashOptions']['threads']);
    }

    public function testParseUserWithMissingHashField(): void
    {
        $content = [
            '$id' => 'user123',
            'email' => 'test@example.com',
            'hashOptions' => [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3
            ]
        ];

        $result = $this->filter->parse($content, 'user');

        // Should default to argon2 if hash field is missing
        $this->assertArrayHasKey('hashOptions', $result);
        $this->assertEquals('argon2', $result['hashOptions']['type']);
        $this->assertEquals(65536, $result['hashOptions']['memoryCost']);
        $this->assertEquals(4, $result['hashOptions']['timeCost']);
        $this->assertEquals(3, $result['hashOptions']['threads']);
    }

    public function testParseUserList(): void
    {
        $content = [
            'users' => [
                [
                    '$id' => 'user1',
                    'email' => 'user1@example.com',
                    'hash' => 'argon2',
                    'hashOptions' => [
                        'memory_cost' => 65536,
                        'time_cost' => 4,
                        'threads' => 3
                    ]
                ],
                [
                    '$id' => 'user2',
                    'email' => 'user2@example.com',
                    'hash' => 'argon2',
                    'hashOptions' => [
                        'memoryCost' => 2048,
                        'timeCost' => 4,
                        'threads' => 3
                    ]
                ]
            ]
        ];

        $result = $this->filter->parse($content, 'userList');

        // Both users should have their hashOptions converted
        $this->assertArrayHasKey('users', $result);
        $this->assertCount(2, $result['users']);

        // First user (snake_case format)
        $user1 = $result['users'][0];
        $this->assertEquals('argon2', $user1['hashOptions']['type']);
        $this->assertEquals(65536, $user1['hashOptions']['memoryCost']);
        $this->assertEquals(4, $user1['hashOptions']['timeCost']);
        $this->assertEquals(3, $user1['hashOptions']['threads']);

        // Second user (camelCase format)
        $user2 = $result['users'][1];
        $this->assertEquals('argon2', $user2['hashOptions']['type']);
        $this->assertEquals(2048, $user2['hashOptions']['memoryCost']);
        $this->assertEquals(4, $user2['hashOptions']['timeCost']);
        $this->assertEquals(3, $user2['hashOptions']['threads']);
    }

    public function testParseNonUserModel(): void
    {
        $content = [
            '$id' => 'project123',
            'name' => 'Test Project',
            'oAuthProviders' => [] // Add missing field to avoid error
        ];

        $result = $this->filter->parse($content, 'project');

        // V17 filter converts oAuthProviders to providers for project model
        $expected = [
            '$id' => 'project123',
            'name' => 'Test Project',
            'providers' => [] // oAuthProviders gets converted to providers
        ];
        $this->assertEquals($expected, $result);
    }
}
