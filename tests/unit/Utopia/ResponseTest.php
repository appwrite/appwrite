<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia;

use Appwrite\Models\Project as GeneratedProject;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Project as ProjectModel;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Http\Response as SwooleResponse;
use Tests\Unit\Utopia\Response\Filters\First;
use Tests\Unit\Utopia\Response\Filters\Second;
use Utopia\Database\Document;

final class ResponseTest extends TestCase
{
    protected ?Response $response = null;

    public function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        $this->response->setModel(new Single());
        $this->response->setModel(new Lists());
        $this->response->setModel(new Nested());
    }

    public function testFilters(): void
    {
        $this->assertFalse($this->response->hasFilters());
        $this->assertEmpty($this->response->getFilters());

        $this->response->addFilter(new First());
        $this->response->addFilter(new Second());

        $this->assertTrue($this->response->hasFilters());
        $this->assertCount(2, $this->response->getFilters());

        $content = [
            'initial' => true,
            'first' => false
        ];
        $output = $this->response->applyFilters($content, 'test', raw: new Document($content));

        $this->assertArrayHasKey('initial', $output);
        $this->assertTrue($output['initial']);
        $this->assertArrayHasKey('first', $output);
        $this->assertTrue($output['first']);
        $this->assertArrayHasKey('second', $output);
        $this->assertTrue($output['second']);
        $this->assertArrayNotHasKey('deleted', $output);
    }

    public function testResponseModel(): void
    {
        $output = $this->response->output(new Document([
            'string' => 'lorem ipsum',
            'integer' => 123,
            'boolean' => true,
            'hidden' => 'secret',
            'array' => [
                'string 1',
                'string 2'
            ],
        ]), 'single');

        $this->assertArrayHasKey('string', $output);
        $this->assertArrayHasKey('integer', $output);
        $this->assertArrayHasKey('boolean', $output);
        $this->assertArrayNotHasKey('hidden', $output);
        $this->assertIsArray($output['array']);

        // test optional array
        $output = $this->response->output(new Document([
            'string' => 'lorem ipsum',
            'integer' => 123,
            'boolean' => true,
            'hidden' => 'secret',
        ]), 'single');
        $this->assertArrayHasKey('string', $output);
        $this->assertArrayHasKey('integer', $output);
        $this->assertArrayHasKey('boolean', $output);
        $this->assertArrayNotHasKey('hidden', $output);
        $this->assertArrayHasKey('array', $output);
        $this->assertNull($output['array']);

    }

    public function testResponseModelRequired(): void
    {
        $output = $this->response->output(new Document([
            'string' => 'lorem ipsum',
            'integer' => 123,
            'boolean' => true,
        ]), 'single');

        $this->assertArrayHasKey('string', $output);
        $this->assertArrayHasKey('integer', $output);
        $this->assertArrayHasKey('boolean', $output);
        $this->assertArrayHasKey('required', $output);
        $this->assertEquals('default', $output['required']);
    }

    public function testResponseModelRequiredException(): void
    {
        $this->expectException(Exception::class);
        $this->response->output(new Document([
            'integer' => 123,
            'boolean' => true,
        ]), 'single');
    }

    public function testProjectResponseCanHydrateGeneratedSdkProjectWithoutOAuth2Fields(): void
    {
        $this->response->setModel(new ProjectModel());

        $project = $this->response->output(new Document([
            '$id' => 'project',
            '$createdAt' => '2026-06-19T00:00:00.000+00:00',
            '$updatedAt' => '2026-06-19T00:00:00.000+00:00',
            'name' => 'Project',
            'teamId' => 'team',
            'region' => 'default',
        ]), Response::MODEL_PROJECT);

        $generated = GeneratedProject::from($project);

        foreach ([
            'oAuth2ServerEnabled',
            'oAuth2ServerAuthorizationUrl',
            'oAuth2ServerScopes',
            'oAuth2ServerAuthorizationDetailsTypes',
            'oAuth2ServerAccessTokenDuration',
            'oAuth2ServerRefreshTokenDuration',
            'oAuth2ServerPublicAccessTokenDuration',
            'oAuth2ServerPublicRefreshTokenDuration',
            'oAuth2ServerConfidentialPkce',
            'oAuth2ServerVerificationUrl',
            'oAuth2ServerUserCodeLength',
            'oAuth2ServerUserCodeFormat',
            'oAuth2ServerDeviceCodeDuration',
            'oAuth2ServerDiscoveryUrl',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $project);
        }

        $this->assertNull($generated->oAuth2ServerEnabled);
        $this->assertNull($generated->oAuth2ServerAuthorizationUrl);
        $this->assertNull($generated->oAuth2ServerScopes);
        $this->assertNull($generated->oAuth2ServerAuthorizationDetailsTypes);
        $this->assertNull($generated->oAuth2ServerAccessTokenDuration);
        $this->assertNull($generated->oAuth2ServerRefreshTokenDuration);
        $this->assertNull($generated->oAuth2ServerPublicAccessTokenDuration);
        $this->assertNull($generated->oAuth2ServerPublicRefreshTokenDuration);
        $this->assertNull($generated->oAuth2ServerConfidentialPkce);
        $this->assertNull($generated->oAuth2ServerVerificationUrl);
        $this->assertNull($generated->oAuth2ServerUserCodeLength);
        $this->assertNull($generated->oAuth2ServerUserCodeFormat);
        $this->assertNull($generated->oAuth2ServerDeviceCodeDuration);
        $this->assertNull($generated->oAuth2ServerDiscoveryUrl);
    }

    public function testResponseModelLists(): void
    {
        $output = $this->response->output(new Document([
            'singles' => [
                new Document([
                    'string' => 'lorem ipsum',
                    'integer' => 123,
                    'boolean' => true,
                    'hidden' => 'secret'
                ])
            ],
            'hidden' => 'secret',
        ]), 'lists');

        $this->assertArrayHasKey('singles', $output);
        $this->assertArrayNotHasKey('hidden', $output);
        $this->assertCount(1, $output['singles']);

        $single = $output['singles'][0];
        $this->assertArrayHasKey('string', $single);
        $this->assertArrayHasKey('integer', $single);
        $this->assertArrayHasKey('boolean', $single);
        $this->assertArrayHasKey('required', $single);
        $this->assertArrayNotHasKey('hidden', $single);
    }

    public function testResponseModelNested(): void
    {
        $output = $this->response->output(new Document([
            'lists' => new Document([
                'singles' => [
                    new Document([
                        'string' => 'lorem ipsum',
                        'integer' => 123,
                        'boolean' => true,
                        'hidden' => 'secret'
                    ])
                ],
                'hidden' => 'secret',
            ]),
            'single' => new Document([
                'string' => 'lorem ipsum',
                'integer' => 123,
                'boolean' => true,
                'hidden' => 'secret'
            ]),
            'hidden' => 'secret',
        ]), 'nested');

        $this->assertArrayHasKey('lists', $output);
        $this->assertArrayHasKey('single', $output);
        $this->assertArrayNotHasKey('hidden', $output);
        $this->assertCount(1, $output['lists']['singles']);


        $single = $output['single'];
        $this->assertArrayHasKey('string', $single);
        $this->assertArrayHasKey('integer', $single);
        $this->assertArrayHasKey('boolean', $single);
        $this->assertArrayHasKey('required', $single);
        $this->assertArrayNotHasKey('hidden', $single);

        $singleFromArray = $output['lists']['singles'][0];
        $this->assertArrayHasKey('string', $singleFromArray);
        $this->assertArrayHasKey('integer', $singleFromArray);
        $this->assertArrayHasKey('boolean', $singleFromArray);
        $this->assertArrayHasKey('required', $single);
        $this->assertArrayNotHasKey('hidden', $singleFromArray);
    }

    public function testShowSensitiveRestoresPreviousState(): void
    {
        $isShowingSensitive = new ReflectionProperty(Response::class, 'showSensitive');

        $this->assertFalse($isShowingSensitive->getValue($this->response));

        $payload = $this->response->showSensitive(function () use ($isShowingSensitive) {
            return [
                'outer' => $isShowingSensitive->getValue($this->response),
                'inner' => $this->response->showSensitive(fn () => [
                    'state' => $isShowingSensitive->getValue($this->response),
                ]),
                'afterInner' => $isShowingSensitive->getValue($this->response),
            ];
        });

        $this->assertTrue($payload['outer']);
        $this->assertTrue($payload['inner']['state']);
        $this->assertTrue($payload['afterInner']);
        $this->assertFalse($isShowingSensitive->getValue($this->response));
    }
}
