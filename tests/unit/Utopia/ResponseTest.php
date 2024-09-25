<?php

namespace Tests\Unit\Utopia;

use Appwrite\Utopia\Response;
use Exception;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Tests\Unit\Utopia\Response\Filters\First;
use Tests\Unit\Utopia\Response\Filters\Second;
use Utopia\Database\Document;

class ResponseTest extends TestCase
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
        $this->assertIsArray($this->response->getFilters());
        $this->assertEmpty($this->response->getFilters());

        $this->response->addFilter(new First());
        $this->response->addFilter(new Second());

        $this->assertTrue($this->response->hasFilters());
        $this->assertCount(2, $this->response->getFilters());

        $output = $this->response->applyFilters([
            'initial' => true,
            'first' => false
        ], 'test');

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
        ]), 'single');

        $this->assertArrayHasKey('string', $output);
        $this->assertArrayHasKey('integer', $output);
        $this->assertArrayHasKey('boolean', $output);
        $this->assertArrayNotHasKey('hidden', $output);
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
}
