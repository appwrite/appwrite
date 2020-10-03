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
        $this->object = new Template(__DIR__.'/../../resources/template.tpl');
        $this->object
            ->setParam('{{world}}', 'WORLD')
        ;
    }

    public function tearDown(): void
    {
    }

    public function testRender()
    {
        $this->assertEquals($this->object->render(), 'Hello WORLD');
    }

    public function testParseURL()
    {
        $url = $this->object->parseURL('https://appwrite.io/demo');

        $this->assertEquals($url['scheme'], 'https');
        $this->assertEquals($url['host'], 'appwrite.io');
        $this->assertEquals($url['path'], '/demo');
    }

    public function testUnParseURL()
    {
        $url = $this->object->parseURL('https://appwrite.io/demo');

        $url['scheme'] = 'http';
        $url['host'] = 'example.com';
        $url['path'] = '/new';

        $this->assertEquals($this->object->unParseURL($url), 'http://example.com/new');
    }

    public function testMergeQuery()
    {
        $this->assertEquals($this->object->mergeQuery('key1=value1&key2=value2', ['key1' => 'value3', 'key4' => 'value4']), 'key1=value3&key2=value2&key4=value4');
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
