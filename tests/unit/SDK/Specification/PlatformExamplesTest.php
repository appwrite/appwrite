<?php

declare(strict_types=1);

namespace Tests\Unit\SDK\Specification;

use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Android;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Apple;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Linux;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Windows;
use Appwrite\SDK\Method;
use Appwrite\SDK\Specification\Format\OpenAPI3;
use Appwrite\Utopia\Response\Model\PlatformAndroid;
use Appwrite\Utopia\Response\Model\PlatformApple;
use Appwrite\Utopia\Response\Model\PlatformLinux;
use Appwrite\Utopia\Response\Model\PlatformWeb;
use Appwrite\Utopia\Response\Model\PlatformWindows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\DI\Container;
use Utopia\Http\Route;
use Utopia\Platform\Action;

final class PlatformExamplesTest extends TestCase
{
    /** @var array<string> */
    private array $processed = [];

    /** @var array<string> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->processed = Method::$processed;
        $this->errors = Method::$errors;
    }

    protected function tearDown(): void
    {
        Method::$processed = $this->processed;
        Method::$errors = $this->errors;
    }

    /**
     * @return iterable<string, array{class-string<Action>, string, string}>
     */
    public static function examples(): iterable
    {
        $examples = [
            'createAndroidPlatform' => [Android\Create::class, ['name' => '<NAME>', 'applicationId' => '<APPLICATION_ID>']],
            'updateAndroidPlatform' => [Android\Update::class, ['name' => '<NAME>', 'applicationId' => '<APPLICATION_ID>']],
            'createApplePlatform' => [Apple\Create::class, ['name' => '<NAME>', 'bundleIdentifier' => '<BUNDLE_IDENTIFIER>']],
            'updateApplePlatform' => [Apple\Update::class, ['name' => '<NAME>', 'bundleIdentifier' => '<BUNDLE_IDENTIFIER>']],
            'createLinuxPlatform' => [Linux\Create::class, ['name' => '<NAME>', 'packageName' => '<PACKAGE_NAME>']],
            'updateLinuxPlatform' => [Linux\Update::class, ['name' => '<NAME>', 'packageName' => '<PACKAGE_NAME>']],
            'createWindowsPlatform' => [Windows\Create::class, ['name' => '<NAME>', 'packageIdentifierName' => '<PACKAGE_IDENTIFIER_NAME>']],
            'updateWindowsPlatform' => [Windows\Update::class, ['name' => '<NAME>', 'packageIdentifierName' => '<PACKAGE_IDENTIFIER_NAME>']],
            'createWebPlatform' => [Web\Create::class, ['name' => '<NAME>', 'hostname' => 'app.example.com']],
            'updateWebPlatform' => [Web\Update::class, ['name' => '<NAME>', 'hostname' => 'app.example.com']],
        ];

        foreach ($examples as $method => [$action, $parameters]) {
            foreach ($parameters as $parameter => $example) {
                yield "{$method} {$parameter}" => [$action, $parameter, $example];
            }
        }
    }

    /**
     * @param class-string<Action> $action
     */
    #[DataProvider('examples')]
    public function testParameterKeepsItsExample(string $action, string $parameter, string $example): void
    {
        $properties = $this->bodyProperties(new $action());

        $this->assertSame($example, $properties[$parameter]['example'] ?? null);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function bodyProperties(Action $action): array
    {
        $methods = $action->getHttpMethods();
        $route = new Route($methods, $action->getHttpPath());

        foreach ($action->getParams() as $key => $param) {
            $route->param($key, $param['default'], $param['validator'], $param['description'], $param['optional'], $param['injections'], $param['skipValidation'], $param['deprecated'], $param['example'], aliases: $param['aliases'], enum: $param['enum']);
        }

        foreach ($action->getLabels() as $key => $label) {
            $route->label($key, $label);
        }

        $container = new Container();
        $container->set('dbForPlatform', fn (): Database => new Database(new Memory(), new Cache(new None())));

        $models = [new PlatformAndroid(), new PlatformApple(), new PlatformLinux(), new PlatformWeb(), new PlatformWindows()];
        $specification = (new OpenAPI3($container, [], [$route], $models, [], ['console' => 0], 'console'))->parse();

        $this->assertCount(1, $specification['paths']);
        [$path] = \array_values($specification['paths']);

        return $path[\strtolower($methods[0])]['requestBody']['content']['application/json']['schema']['properties'];
    }
}
