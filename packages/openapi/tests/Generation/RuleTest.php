<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Tests\Generation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Generation\Rule;

final class RuleTest extends TestCase
{
    /** @return iterable<string, array{Rule, string}> */
    public static function accepted(): iterable
    {
        yield 'name' => [Rule::Name, 'createDocument'];
        yield 'field with a dollar prefix' => [Rule::Field, '$id'];
        yield 'path with a template and a colon' => [Rule::Path, '/users/{userId}:activate'];
        yield 'url' => [Rule::Url, 'https://cloud.appwrite.io/v1'];
        yield 'title' => [Rule::Title, 'Appwrite'];
        yield 'empty version' => [Rule::Version, ''];
        yield 'version' => [Rule::Version, '1.8.0-rc.1'];
        yield 'media type' => [Rule::MediaType, 'application/json'];
        yield 'media type range' => [Rule::MediaType, '*/*'];
        yield 'media type with parameters' => [Rule::MediaType, 'application/vnd.api+json; charset=utf-8; profile=a!b'];
        yield 'empty media type' => [Rule::MediaType, ''];
        yield 'enum value with spaces and dots' => [Rule::EnumValue, 'teams.read and write'];
        yield 'enum name with an apostrophe' => [Rule::EnumName, "Côte d'Ivoire"];
    }

    /** @return iterable<string, array{Rule, string}> */
    public static function refused(): iterable
    {
        yield 'name with a quote' => [Rule::Name, 'create"Document'];
        yield 'name with a trailing newline' => [Rule::Name, "createDocument\n"];
        yield 'field with an inner dollar' => [Rule::Field, 'a$b'];
        yield 'path with a quote' => [Rule::Path, "/users/'"];
        yield 'path with a dollar' => [Rule::Path, '/users/$id'];
        yield 'path that is not absolute' => [Rule::Path, 'users'];
        yield 'url with a quote' => [Rule::Url, 'https://example.com/"'];
        yield 'url that is not http' => [Rule::Url, 'javascript:alert(1)'];
        yield 'title with a backslash' => [Rule::Title, 'App\\write'];
        yield 'version with a space' => [Rule::Version, '1.0 beta'];
        yield 'media type with a quote' => [Rule::MediaType, 'application/json"'];
        yield 'media type without a subtype' => [Rule::MediaType, 'application'];
        yield 'enum value with a backtick' => [Rule::EnumValue, 'a`b'];
        yield 'enum value with a line separator' => [Rule::EnumValue, "a\u{2028}b"];
        yield 'enum name with a line break' => [Rule::EnumName, "a\nb"];
    }

    #[DataProvider('accepted')]
    public function testAccepts(Rule $rule, string $value): void
    {
        self::assertTrue($rule->accepts($value));
    }

    #[DataProvider('refused')]
    public function testRefuses(Rule $rule, string $value): void
    {
        self::assertFalse($rule->accepts($value));
    }
}
