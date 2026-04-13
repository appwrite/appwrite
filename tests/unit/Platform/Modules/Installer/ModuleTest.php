<?php

namespace Tests\Unit\Platform\Modules\Installer;

use Appwrite\Platform\Installer\Http\Installer\Complete;
use Appwrite\Platform\Installer\Http\Installer\Error;
use Appwrite\Platform\Installer\Http\Installer\Install;
use Appwrite\Platform\Installer\Http\Installer\Reset;
use Appwrite\Platform\Installer\Http\Installer\Shutdown;
use Appwrite\Platform\Installer\Http\Installer\Status;
use Appwrite\Platform\Installer\Http\Installer\Validate;
use Appwrite\Platform\Installer\Http\Installer\View;
use Appwrite\Platform\Installer\Module;
use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;
use Utopia\Platform\Platform;
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

        $this->assertCount(8, $actions);
        $this->assertArrayHasKey('installerView', $actions);
        $this->assertArrayHasKey('installerStatus', $actions);
        $this->assertArrayHasKey('installerValidate', $actions);
        $this->assertArrayHasKey('installerComplete', $actions);
        $this->assertArrayHasKey('installerShutdown', $actions);
        $this->assertArrayHasKey('installerReset', $actions);
        $this->assertArrayHasKey('installerInstall', $actions);
        $this->assertArrayHasKey('installerCertificateGet', $actions);
    }

    public function testViewAction(): void
    {
        $action = $this->getAction('installerView');

        $this->assertEquals('installerView', View::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_GET, $action->getHttpMethod());
        $this->assertEquals('/', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionParams($action, ['step', 'partial']);
        $this->assertActionInjects($action, ['request', 'response', 'installerConfig', 'installerPaths']);
    }

    public function testStatusAction(): void
    {
        $action = $this->getAction('installerStatus');

        $this->assertEquals('installerStatus', Status::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_GET, $action->getHttpMethod());
        $this->assertEquals('/install/status', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionParams($action, ['installId']);
        $this->assertActionInjects($action, ['response', 'installerState']);
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
        $this->assertActionParams($action, ['installId', 'sessionId', 'sessionSecret', 'sessionExpire']);
        $this->assertActionInjects($action, ['request', 'response', 'installerState']);
    }

    public function testShutdownAction(): void
    {
        $action = $this->getAction('installerShutdown');

        $this->assertEquals('installerShutdown', Shutdown::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_POST, $action->getHttpMethod());
        $this->assertEquals('/install/shutdown', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionInjects($action, ['request', 'response', 'swooleServer']);
    }

    public function testResetAction(): void
    {
        $action = $this->getAction('installerReset');

        $this->assertEquals('installerReset', Reset::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_POST, $action->getHttpMethod());
        $this->assertEquals('/install/reset', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionParams($action, ['installId', 'hard']);
        $this->assertActionInjects($action, ['request', 'response', 'installerState', 'installerConfig']);
    }

    public function testInstallAction(): void
    {
        $action = $this->getAction('installerInstall');

        $this->assertEquals('installerInstall', Install::getName());
        $this->assertEquals(Action::HTTP_REQUEST_METHOD_POST, $action->getHttpMethod());
        $this->assertEquals('/install', $action->getHttpPath());
        $this->assertEquals(Action::TYPE_DEFAULT, $action->getType());
        $this->assertActionParams($action, [
            'appDomain', 'httpPort', 'httpsPort', 'emailCertificates', 'opensslKey',
            'assistantOpenAIKey', 'accountEmail', 'accountPassword', 'database',
            'installId', 'retryStep', 'migrate',
        ]);
        $this->assertActionInjects($action, ['request', 'response', 'swooleResponse', 'installerState', 'installerConfig', 'installerPaths']);
    }

    public function testErrorActionClass(): void
    {
        $error = new Error();

        $this->assertEquals('installerError', Error::getName());
        $this->assertEquals(Action::TYPE_ERROR, $error->getType());
        $this->assertIsCallable($error->getCallback());
    }

    /**
     * @runInSeparateProcess
     */
    public function testRouteRegistration(): void
    {
        $platform = new class (new Module()) extends Platform {};
        $platform->init(Service::TYPE_HTTP);

        // If we get here without exceptions, route registration succeeded
        $this->assertTrue(true);
    }

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

    public function testAllDefaultActionsHaveDescriptions(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        foreach ($service->getActions() as $name => $action) {
            $desc = $action->getDesc();
            $this->assertNotNull($desc, "Action '$name' should have a description");
            $this->assertNotEmpty($desc, "Action '$name' description should not be empty");
        }
    }

    public function testAllActionsHaveCallableCallbacks(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        foreach ($service->getActions() as $name => $action) {
            $callback = $action->getCallback();
            $this->assertIsCallable($callback, "Action '$name' callback should be callable");
        }
    }

    public function testActionNamesAreUnique(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        $actions = $service->getActions();
        $names = array_keys($actions);
        $this->assertEquals($names, array_unique($names));
    }

    public function testRoutePathsAreUniquePerMethod(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        $routes = [];
        foreach ($service->getActions() as $action) {
            $key = $action->getHttpMethod() . ' ' . $action->getHttpPath();
            $this->assertArrayNotHasKey($key, $routes, "Duplicate route: $key");
            $routes[$key] = true;
        }
    }

    public function testStaticGetNameValues(): void
    {
        $this->assertEquals('installerView', View::getName());
        $this->assertEquals('installerStatus', Status::getName());
        $this->assertEquals('installerValidate', Validate::getName());
        $this->assertEquals('installerComplete', Complete::getName());
        $this->assertEquals('installerShutdown', Shutdown::getName());
        $this->assertEquals('installerReset', Reset::getName());
        $this->assertEquals('installerInstall', Install::getName());
        $this->assertEquals('installerError', Error::getName());
    }

    public function testActionInstanceTypes(): void
    {
        $services = $this->module->getServicesByType(Service::TYPE_HTTP);
        $service = reset($services);
        $actions = $service->getActions();

        $this->assertInstanceOf(View::class, $actions['installerView']);
        $this->assertInstanceOf(Status::class, $actions['installerStatus']);
        $this->assertInstanceOf(Validate::class, $actions['installerValidate']);
        $this->assertInstanceOf(Complete::class, $actions['installerComplete']);
        $this->assertInstanceOf(Shutdown::class, $actions['installerShutdown']);
        $this->assertInstanceOf(Reset::class, $actions['installerReset']);
        $this->assertInstanceOf(Install::class, $actions['installerInstall']);
    }

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
        $postActions = ['installerValidate', 'installerComplete', 'installerShutdown', 'installerReset', 'installerInstall'];
        foreach ($postActions as $name) {
            $action = $this->getAction($name);
            $this->assertEquals(
                Action::HTTP_REQUEST_METHOD_POST,
                $action->getHttpMethod(),
                "Action '$name' should use POST method"
            );
        }
    }

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

    private function assertActionParams(Action $action, array $expectedParams): void
    {
        $params = [];
        foreach ($action->getOptions() as $key => $option) {
            if ($option['type'] === 'param') {
                $params[] = substr($key, 6); // strip 'param:' prefix
            }
        }
        $this->assertEquals($expectedParams, $params);
    }
}
