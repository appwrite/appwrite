<?php

namespace Appwrite\Tests;

use Appwrite\Template\Template;
use PHPUnit\Framework\TestCase;

class TemplateTest extends TestCase
{
    /**
     * @var Template
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new Template(__DIR__ . '/../../resources/template.tpl');
        $this->object
            ->setParam('{{world}}', 'WORLD')
        ;
    }

    public function tearDown(): void
    {
    }

    public function testRender()
    {
        $this->assertEquals('Hello WORLD', $this->object->render());
    }

    public function testParseURL()
    {
        $url = $this->object->parseURL('https://appwrite.io/demo');

        $this->assertEquals('https', $url['scheme']);
        $this->assertEquals('appwrite.io', $url['host']);
        $this->assertEquals('/demo', $url['path']);
    }

    public function testUnParseURL()
    {
        $url = $this->object->parseURL('https://appwrite.io/demo');

        $url['scheme'] = 'http';
        $url['host'] = 'example.com';
        $url['path'] = '/new';

        $this->assertEquals('http://example.com/new', $this->object->unParseURL($url));
    }

    public function testMergeQuery()
    {
        $this->assertEquals('key1=value3&key2=value2&key4=value4', $this->object->mergeQuery('key1=value1&key2=value2', ['key1' => 'value3', 'key4' => 'value4']));
    }

    public function testFromCamelCaseToSnake()
    {
        $this->assertEquals('app_write', Template::fromCamelCaseToSnake('appWrite'));
        $this->assertEquals('app_write', Template::fromCamelCaseToSnake('App Write'));
    }

    public function testFromCamelCaseToDash()
    {
        $this->assertEquals('app-write', Template::fromCamelCaseToDash('appWrite'));
        $this->assertEquals('app-write', Template::fromCamelCaseToDash('App Write'));
    }
}
