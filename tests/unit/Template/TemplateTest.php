<?php

declare(strict_types=1);

namespace Tests\Unit\Template;

use Appwrite\Template\Template;
use PHPUnit\Framework\TestCase;

final class TemplateTest extends TestCase
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

    public function testRender(): void
    {
        $this->assertEquals('Hello WORLD', $this->object->render());
    }

    public function testRenderKeepsPlaceholdersInValuesLiteral(): void
    {
        $template = Template::fromString('<a href="{{redirect}}">{{team}}</a>');
        $template
            ->setParam('{{redirect}}', 'https://{{team}}.example.com/join?secret=abc')
            ->setParam('{{team}}', 'evil.com/x?');

        $this->assertSame('<a href="https://{{team}}.example.com/join?secret=abc">evil.com/x?</a>', $template->render(useContent: true));
    }

    public function testRenderDoesNotExpandSelfReferences(): void
    {
        $value = \str_repeat('{{client}}', 100);
        $template = Template::fromString('{{client}} {{client}}');
        $template->setParam('{{client}}', $value);

        $this->assertSame($value . ' ' . $value, $template->render(useContent: true));
    }

    public function testParseURL(): void
    {
        $url = $this->object->parseURL('https://appwrite.io/demo');

        $this->assertEquals('https', $url['scheme']);
        $this->assertEquals('appwrite.io', $url['host']);
        $this->assertEquals('/demo', $url['path']);
    }

    public function testUnParseURL(): void
    {
        $url = $this->object->parseURL('https://appwrite.io/demo');

        $url['scheme'] = 'http';
        $url['host'] = 'example.com';
        $url['path'] = '/new';

        $this->assertEquals('http://example.com/new', $this->object->unParseURL($url));
    }

    public function testMergeQuery(): void
    {
        $this->assertEquals('key1=value3&key2=value2&key4=value4', $this->object->mergeQuery('key1=value1&key2=value2', ['key1' => 'value3', 'key4' => 'value4']));
    }

    public function testFromCamelCaseToSnake(): void
    {
        $this->assertSame('app_write', Template::fromCamelCaseToSnake('appWrite'));
        $this->assertSame('app_write', Template::fromCamelCaseToSnake('App Write'));
    }

    public function testFromCamelCaseToDash(): void
    {
        $this->assertSame('app-write', Template::fromCamelCaseToDash('appWrite'));
        $this->assertSame('app-write', Template::fromCamelCaseToDash('App Write'));
    }
}
