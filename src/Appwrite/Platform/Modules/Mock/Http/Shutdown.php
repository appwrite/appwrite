<?php

namespace Appwrite\Platform\Modules\Mock\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Http\Route;
use Utopia\Platform\Action;

class Shutdown extends Action
{
    public static function getName(): string
    {
        return 'shutdown';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_SHUTDOWN)
            ->groups(['mock'])
            ->inject('response')
            ->inject('route')
            ->callback($this->action(...));
    }

    public function action(Response $response, Route $route): void
    {
        $result = [];
        $path   = APP_STORAGE_CACHE . '/tests.json';
        $tests  = (\file_exists($path)) ? \json_decode(\file_get_contents($path), true) : [];
        $methods = $route->getMethods();

        if (!\is_array($tests)) {
            throw new Exception(Exception::GENERAL_MOCK, 'Failed to read results', 500);
        }

        foreach ($methods as $method) {
            $result[$method . ':' . $route->getPath()] = true;
        }

        $tests = \array_merge($tests, $result);

        if (!\file_put_contents($path, \json_encode($tests), LOCK_EX)) {
            throw new Exception(Exception::GENERAL_MOCK, 'Failed to save results', 500);
        }

        $response->dynamic(new Document(['result' => \implode(',', $methods) . ':' . $route->getPath() . ':passed']), Response::MODEL_MOCK);
    }
}
