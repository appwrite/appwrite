<?php

declare(strict_types=1);

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\Specification\Platform;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    public function testFiltersOperationsMethodsAuthenticationAndSecuritySchemes(): void
    {
        $spec = [
            'x-appwrite' => [
                'platforms' => [
                    'client' => [
                        'authCount' => 1,
                        'securitySchemes' => ['Project', 'Session'],
                    ],
                    'server' => [
                        'authCount' => 2,
                        'securitySchemes' => ['Project', 'Key'],
                    ],
                ],
            ],
            'paths' => [
                '/account' => [
                    'get' => [
                        'x-appwrite' => [
                            'platforms' => ['client', 'server'],
                            'auth' => [
                                'Project' => [],
                                'Session' => [],
                                'Key' => [],
                            ],
                            'methods' => [
                                [
                                    'name' => 'get',
                                    'platforms' => ['client'],
                                    'auth' => [
                                        'Project' => [],
                                        'Session' => [],
                                    ],
                                ],
                                [
                                    'name' => 'get',
                                    'platforms' => ['server'],
                                    'auth' => [
                                        'Project' => [],
                                        'Key' => [],
                                    ],
                                ],
                            ],
                        ],
                        'security' => [[
                            'Project' => [],
                            'Session' => [],
                            'Key' => [],
                        ]],
                    ],
                ],
                '/users' => [
                    'get' => [
                        'x-appwrite' => [
                            'platforms' => ['server'],
                            'auth' => [
                                'Project' => [],
                                'Key' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'Session' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-Appwrite-Session',
                        'x-appwrite' => ['platforms' => ['client']],
                    ],
                    'Project' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-Appwrite-Project',
                        'x-appwrite' => ['platforms' => ['client', 'server']],
                    ],
                    'Key' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-Appwrite-Key',
                        'x-appwrite' => ['platforms' => ['server']],
                    ],
                ],
            ],
        ];

        $client = Platform::filter($spec, 'client');
        $operation = $client['paths']['/account']['get'];

        $this->assertArrayNotHasKey('/users', $client['paths']);
        $this->assertSame(['Project', 'Session'], \array_keys($client['components']['securitySchemes']));
        $this->assertSame(['Project'], \array_keys($operation['x-appwrite']['auth']));
        $this->assertSame(['Project', 'Session'], \array_keys($operation['security'][0]));
        $this->assertCount(1, $operation['x-appwrite']['methods']);
        $this->assertSame('get', $operation['x-appwrite']['methods'][0]['name']);
        $this->assertSame(['Project'], \array_keys($operation['x-appwrite']['methods'][0]['auth']));
    }

    public function testAppliesPlatformSecuritySchemeOverride(): void
    {
        $spec = [
            'x-appwrite' => [
                'platforms' => [
                    'manager' => ['authCount' => 1],
                ],
            ],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'Key' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-Appwrite-Key',
                        'x-appwrite' => [
                            'platforms' => ['server', 'manager'],
                            'overrides' => [
                                'manager' => [
                                    'type' => 'http',
                                    'scheme' => 'bearer',
                                    'bearerFormat' => 'JWT',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $manager = Platform::filter($spec, 'manager');

        $this->assertSame([
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
        ], $manager['components']['securitySchemes']['Key']);
    }
}
