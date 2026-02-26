<?php

namespace Tests\Unit\Platform\Modules\Installer;

use Appwrite\Platform\Installer\Http\Installer\Complete;
use Appwrite\Platform\Installer\Http\Installer\Error;
use Appwrite\Platform\Installer\Http\Installer\Install;
use Appwrite\Platform\Installer\Http\Installer\Status;
use Appwrite\Platform\Installer\Http\Installer\Validate;
use Appwrite\Platform\Installer\Http\Installer\View;
use Appwrite\Platform\Installer\Module;
use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;
use Utopia\Platform\Service;

class ModuleTest extends TestCase
{
    protected ?Module $module = null;

    protected function setUp(): void
    {
        $this->module = new Module();
    }

    protected function tearDown(): void
    {
        $this->module = null;
    }

    public function testModuleHasHttpService(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $this->assertCount(1, $services);
    }

    public function testHttpServiceRegistersAllActions(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        $actions = $service->getActions();

        $this->assertCount(6, $actions);
        $this->assertArrayHasKey('installerView', $actions);
        $this->assertArrayHasKey('installerStatus', $actions);
        $this->assertArrayHasKey('installerValidate', $actions);
        $this->assertArrayHasKey('installerComplete', $actions);
        $this->assertArrayHasKey('installerInstall', $actions);
        $this->assertArrayHasKey('installerError', $actions);
    }

    public function testViewAction(): void
    {
        $action = $this->getAction('installerView');

        $this->assertEquals('installerView', View::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_GET, $action->getHttpMethod());
        $this->assertEquals('/', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionInjects($action, ['request', 'response', 'installerConfig', 'installerPaths']);
    }

    public function testStatusAction(): void
    {
        $action = $this->getAction('installerStatus');

        $this->assertEquals('installerStatus', Status::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_GET, $action->getHttpMethod());
        $this->assertEquals('/install/status', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionInjects($action, ['request', 'response', 'installerState']);
    }

    public function testValidateAction(): void
    {
        $action = $this->getAction('installerValidate');

        $this->assertEquals('installerValidate', Validate::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_POST, $action->getHttpMethod());
        $this->assertEquals('/install/validate', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionInjects($action, ['request', 'response']);
    }

    public function testCompleteAction(): void
    {
        $action = $this->getAction('installerComplete');

        $this->assertEquals('installerComplete', Complete::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_POST, $action->getHttpMethod());
        $this->assertEquals('/install/complete', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionInjects($action, ['request', 'response', 'installerState', 'swooleServer']);
    }

    public function testInstallAction(): void
    {
        $action = $this->getAction('installerInstall');

        $this->assertEquals('installerInstall', Install::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_POST, $action->getHttpMethod());
        $this->assertEquals('/install', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionInjects($action, ['request', 'response', 'swooleResponse', 'installerState', 'installerConfig', 'installerPaths']);
    }

    public function testErrorAction(): void
    {
        $action = $this->getAction('installerError');

        $this->assertEquals('installerError', Error::getName());
        $this->assertEquals(Action::TYPE_ERROR, $action->getType());
        $this->assertActionInjects($action, ['error', 'response']);
    }

    public function testRouteRegistration(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);

        foreach ($services as $service) {
            foreach ($service->getActions() as $action) {
                $type = $action->getType();

                if ($type === Action::TYPE_ERROR) {
                    $hook = \Utopia\Http\Http::error();
                } else {
                    $httpMethod = $action->getHttpMethod();
                    $httpPath = $action->getHttpPath();
                    $this->assertNotNull($httpMethod, 'HTTP method must be set for default actions');
                    $this->assertNotNull($httpPath, 'HTTP path must be set for default actions');
                    $hook = \Utopia\Http\Http::addRoute($httpMethod, $httpPath);
                }

                $hook->desc($action->getDesc() ?? '');

                foreach ($action->getOptions() as $option) {
                    if ($option['type'] === 'injection') {
                        $hook->inject($option['name']);
                    }
                }

                $hook->action($action->getCallback());
            }
        }

        // If we get here without exceptions, route registration succeeded
        $this->assertTrue(true);
    }

    // --- Module service type coverage ---

    public function testModuleHasNoTaskServices(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_TASK);
        $this->assertEmpty($services);
    }

    public function testModuleHasNoWorkerServices(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_WORKER);
        $this->assertEmpty($services);
    }

    // --- Action descriptions ---

    public function testAllDefaultActionsHaveDescriptions(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        foreach ($service->getActions() as $name => $action) {
            $desc = $action->getDesc();
            if ($action->getType() !== Action::TYPE_ERROR) {
                $this->assertNotNull($desc, "Action '$name' should have a description");
                $this->assertNotEmpty($desc, "Action '$name' description should not be empty");
            }
        }
    }

    // --- Action callbacks ---

    public function testAllActionsHaveCallableCallbacks(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        foreach ($service->getActions() as $name => $action) {
            $callback = $action->getCallback();
            $this->assertIsCallable($callback, "Action '$name' callback should be callable");
        }
    }

    // --- Error action specifics ---

    public function testErrorActionHasNoHttpMethod(): void
    {
        $action = $this->getAction('installerError');
        $this->assertNull($action->getHttpMethod());
    }

    public function testErrorActionHasNoHttpPath(): void
    {
        $action = $this->getAction('installerError');
        $this->assertNull($action->getHttpPath());
    }

    // --- Action names are unique ---

    public function testActionNamesAreUnique(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        $actions = $service->getActions();
        $names = array_keys($actions);
        $this->assertEquals($names, array_unique($names));
    }

    // --- Route paths are unique per method ---

    public function testRoutePathsAreUniquePerMethod(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        $routes = [];
        foreach ($service->getActions() as $action) {
            if ($action->getType() === Action::TYPE_ERROR) {
                continue;
            }
            $key = $action->getHttpMethod() . ' ' . $action->getHttpPath();
            $this->assertArrayNotHasKey($key, $routes, "Duplicate route: $key");
            $routes[$key] = true;
        }
    }

    // --- Static getName returns correct values ---

    public function testStaticGetNameValues(): void
    {
        $this->assertEquals('installerView', View::getName());
        $this->assertEquals('installerStatus', Status::getName());
        $this->assertEquals('installerValidate', Validate::getName());
        $this->assertEquals('installerComplete', Complete::getName());
        $this->assertEquals('installerInstall', Install::getName());
        $this->assertEquals('installerError', Error::getName());
    }

    // --- Action instances are correct types ---

    public function testActionInstanceTypes(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        $actions = $service->getActions();

        $this->assertInstanceOf(View::class, $actions['installerView']);
        $this->assertInstanceOf(Status::class, $actions['installerStatus']);
        $this->assertInstanceOf(Validate::class, $actions['installerValidate']);
        $this->assertInstanceOf(Complete::class, $actions['installerComplete']);
        $this->assertInstanceOf(Install::class, $actions['installerInstall']);
        $this->assertInstanceOf(Error::class, $actions['installerError']);
    }

    // --- GET routes use GET method, POST routes use POST ---

    public function testGetRoutesUseGetMethod(): void
    {
        $getActions = ['installerView', 'installerStatus'];
        foreach ($getActions as $name) {
            $action = $this->getAction($name);
            $this->assertEquals(
                Action::HTTP_REQUEST_METHOD_GET,
                $action->getHttpMethod(),
                "Action '$name' should use GET method"
            );
        }
    }

    public function testPostRoutesUsePostMethod(): void
    {
        $postActions = ['installerValidate', 'installerComplete', 'installerInstall'];
        foreach ($postActions as $name) {
            $action = $this->getAction($name);
            $this->assertEquals(
                Action::HTTP_REQUEST_METHOD_POST,
                $action->getHttpMethod(),
                "Action '$name' should use POST method"
            );
        }
    }

    // --- Validate action exposes static CSRF method ---

    public function testValidateClassHasCsrfMethod(): void
    {
        $this->assertTrue(
            method_exists(Validate::class, 'validateCsrf'),
            'Validate class should expose validateCsrf method'
        );
    }

    private function getAction(string $name): Action
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        $actions = $service->getActions();
        $this->assertArrayHasKey($name, $actions);
        return $actions[$name];
    }

    private function assertActionInjects(Action $action, array $expectedInjections): void
    {
        $injections = [];
        foreach ($action->getOptions() as $option) {
            if ($option['type'] === 'injection') {
                $injections[] = $option['name'];
            }
        }
        $this->assertEquals($expectedInjections, $injections);
    }
}
