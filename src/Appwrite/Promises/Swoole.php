<?php

namespace Appwrite\Promises;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\DI\Container;

class Swoole extends Promise
{
    private const REQUEST_CONTAINER_CONTEXT_KEY = '__utopia_http_request_container';

    public function __construct(?callable $executor = null)
    {
        parent::__construct($executor);
    }

    protected function execute(
        callable $executor,
        callable $resolve,
        callable $reject
    ): void {
        $parentContainer = (Coroutine::getCid() !== -1)
            ? (Coroutine::getContext()[self::REQUEST_CONTAINER_CONTEXT_KEY] ?? null)
            : null;

        \go(function () use ($executor, $resolve, $reject, $parentContainer) {
            if ($parentContainer !== null) {
                Coroutine::getContext()[self::REQUEST_CONTAINER_CONTEXT_KEY] = new Container($parentContainer);
            }
            try {
                $executor($resolve, $reject);
            } catch (\Throwable $exception) {
                $reject($exception);
            }
        });
    }

    /**
     * Returns a promise that completes when all passed in promises complete.
     *
     * @param iterable|Swoole[] $promises
     * @return Promise
     */
    public static function all(iterable $promises): Promise
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $ticks = count($promises);

            $result = [];
            $error = null;
            $channel = new Channel($ticks);
            $key = 0;

            foreach ($promises as $promise) {
                $promise->then(function ($value) use ($key, &$result, $channel) {
                    $result[$key] = $value;
                    $channel->push(true);
                    return $value;
                }, function ($err) use ($channel, &$error) {
                    $channel->push(true);
                    if ($error === null) {
                        $error = $err;
                    }
                });
                $key++;
            }
            while ($ticks--) {
                $channel->pop();
            }
            $channel->close();

            if ($error !== null) {
                $reject($error);
                return;
            }

            $resolve($result);
        });
    }
}
