<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Rule;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Database\Document;

final class RuleTest extends TestCase
{
    private Response $response;

    protected function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        $this->response->setModel(new Rule());
    }

    public function testEmptyDeploymentResourceTypeIsNull(): void
    {
        $rule = $this->response->output(new Document([
            'type' => 'api',
            'deploymentResourceType' => '',
        ]), Response::MODEL_PROXY_RULE);

        $this->assertArrayHasKey('deploymentResourceType', $rule);
        $this->assertNull($rule['deploymentResourceType']);
    }

    public function testDeploymentResourceTypeIsPreserved(): void
    {
        $rule = $this->response->output(new Document([
            'type' => 'deployment',
            'deploymentResourceType' => 'site',
        ]), Response::MODEL_PROXY_RULE);

        $this->assertSame('site', $rule['deploymentResourceType']);
    }

    public function testDeploymentResourceTypeIsOptional(): void
    {
        $rules = (new Rule())->getRules();

        $this->assertFalse($rules['deploymentResourceType']['required']);
    }
}
