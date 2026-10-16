<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Every `description` param across the API is optional, and Utopia skips validation
 * for an optional param whose value is an explicit null:
 *
 *     // Utopia\Http\Http::validate()
 *     if ($param['optional'] && \is_null($value)) {
 *         return;
 *     }
 *
 * The raw null then reaches the action, because Http::getArguments() only falls back
 * to the declared default when the key is absent from the request altogether:
 *
 *     $arg = $existsInRequest ? $requestParams[$requestKey] : $param['default'];
 *
 * So `{"description": null}` arrives as null whatever the declared default is. An
 * action declaring a non-nullable `string $description` raises a TypeError and the
 * request 500s instead of being treated as "not provided". This pins the signature
 * half of the contract, which is the same one `schedule` already follows in
 * Functions/Http/Functions/Create.php.
 *
 * What each endpoint then does with that null is behaviour, and is covered by the
 * e2e suites: on create it means "not provided" and resolves to the declared empty
 * default, on update it means "keep the saved value" so only '' clears. It is not
 * asserted here because the attribute and column endpoints forward the null to the
 * shared Attributes\Action::updateAttribute() helper, which applies the "keep"
 * guard across a call boundary that a per-file check cannot see.
 */
final class DescriptionParamTest extends TestCase
{
    /**
     * `projects.update` carries the console project's own pre-existing description
     * field, not one of the resource descriptions added here. Its nine sibling
     * params (logo, url, legalName, ...) share the same non-nullable shape and the
     * same unconditional overwrite, so bringing that endpoint into line belongs in
     * its own change rather than being half-done here.
     */
    private const array PRE_EXISTING = [
        'src/Appwrite/Platform/Modules/Projects/Http/Projects/Update.php',
    ];

    /**
     * The action must accept the null that Utopia will hand it.
     */
    #[DataProvider('descriptionEndpoints')]
    public function testActionAcceptsAnExplicitNullDescription(string $class, string $file): void
    {
        $parameter = null;
        foreach ((new ReflectionMethod($class, 'action'))->getParameters() as $candidate) {
            if ($candidate->getName() === 'description') {
                $parameter = $candidate;
                break;
            }
        }

        if ($parameter === null) {
            // A subclass that re-declares the params and reuses its parent's
            // action(), e.g. TablesDB\Create extends Databases\Create. The parent
            // carries the signature and is covered as its own case.
            $this->assertNotSame(
                $class,
                (new ReflectionMethod($class, 'action'))->getDeclaringClass()->getName(),
                $file . ' declares a description param but its own action() takes no $description.',
            );

            return;
        }

        $type = $parameter->getType();

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $type,
            $file . ': $description has no type declaration.',
        );

        $this->assertTrue(
            $type->allowsNull(),
            $file . ': action() declares a non-nullable $description, so an explicit'
            . ' "description": null raises a TypeError and the request 500s. Declare'
            . ' ?string $description and coalesce it, as schedule does.',
        );
    }

    /**
     * Every endpoint that declares an optional `description` param, discovered from
     * the source so a newly added endpoint is covered without touching this test.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function descriptionEndpoints(): iterable
    {
        $root = self::root();
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src/Appwrite/Platform/Modules'),
        );

        $endpoints = [];
        foreach ($directory as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $file = \substr($entry->getPathname(), \strlen($root) + 1);

            if (\in_array($file, self::PRE_EXISTING, true)) {
                continue;
            }

            $source = \file_get_contents($entry->getPathname());

            if ($source === false || !\str_contains($source, "->param('description'")) {
                continue;
            }

            // Only the optional ones: Utopia's null-skips-validation path is reached
            // through the `optional` flag.
            if (!\preg_match("/->param\(\s*'description',.*,\s*true[\s,)]/U", $source)) {
                continue;
            }

            if (!\preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
                continue;
            }

            $class = \trim($namespace[1]) . '\\' . $entry->getBasename('.php');

            if (!\class_exists($class) || !\method_exists($class, 'action')) {
                continue;
            }

            $endpoints[$file] = [$class, $file];
        }

        \ksort($endpoints);

        return $endpoints;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
