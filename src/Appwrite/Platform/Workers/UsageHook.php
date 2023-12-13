<?php

namespace Appwrite\Platform\Workers;

use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Swoole\Timer;

class UsageHook extends Usage
{
    public static function getName(): string
    {
        return 'usageHook';
    }

    public function __construct()
    {

        $this
            ->setType(Action::TYPE_WORKER_START)
            ->inject('register')
            ->inject('cache')
            ->inject('pools')
            ->callback(function ($register, $cache, $pools) {
                $this->action($register, $cache, $pools);
            })
        ;
    }

    /**
     * @param $register
     * @param $cache
     * @param $pools
     * @return void
     */
    public function action($register, $cache, $pools): void
    {
        $interval = (int) App::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '50000');
        Timer::tick($interval, function () use ($register, $cache, $pools) {

            $offset = count(self::$stats);
            $projects = array_slice(self::$stats, 0, $offset, true);
            array_splice(self::$stats, 0, $offset);

            foreach ($projects as $projectInternalId => $project) {
                try {
                    $dbForProject = new Database(
                        $pools
                            ->get($project['database'])
                            ->pop()
                            ->getResource(),
                        $cache
                    );

                    $dbForProject->setNamespace('_' . $projectInternalId);

                    foreach ($project['keys'] ?? [] as $key => $value) {
                        if ($value == 0) {
                            continue;
                        }

                        foreach ($this->periods as $period => $format) {
                            $time = 'inf' === $period ? null : date($format, time());
                            $id = \md5("{$time}_{$period}_{$key}");

                            /**
                             * Infinity daily
                             */
                            if ('infd' === $period) {
                                $isInfDaily = array_reduce(["files", "buckets", "users"], function ($carry, $word) use ($key) {
                                    return $carry || str_contains($key, $word);
                                }, false);

                                if (!$isInfDaily) {
                                    continue;
                                }

                                $infinity = $dbForProject->getDocument('stats', \md5(self::INFINITY_PERIOD . $key));
                                $infinityDaily = $dbForProject->getDocument('stats', $id);

                                if ($infinityDaily->isEmpty()) {
                                    $dbForProject->createDocument('stats', new Document([
                                        '$id' => $id,
                                        'period' => $period,
                                        'time' => $time,
                                        'metric' => $key,
                                        'value' => $infinity['value'] ?? 0,
                                        'region' => App::getEnv('_APP_REGION', 'default'),
                                    ]));
                                } else {
                                    $infinityDaily->setAttribute('value', $infinity['value'] ?? 0);
                                    $dbForProject->updateDocument('stats', $infinityDaily->getId(), $infinityDaily);
                                }
                                continue;
                            }


                            try {
                                $dbForProject->createDocument('stats', new Document([
                                    '$id' => $id,
                                    'period' => $period,
                                    'time' => $time,
                                    'metric' => $key,
                                    'value' => $value,
                                    'region' => App::getEnv('_APP_REGION', 'default'),
                                ]));
                            } catch (Duplicate $th) {
                                if ($value < 0) {
                                    $dbForProject->decreaseDocumentAttribute(
                                        'stats',
                                        $id,
                                        'value',
                                        abs($value)
                                    );
                                } else {
                                    $dbForProject->increaseDocumentAttribute(
                                        'stats',
                                        $id,
                                        'value',
                                        $value
                                    );
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    console::error("[logger] " . " {DateTime::now()} " .  " {$projectInternalId} " . " {$e->getMessage()}");
                } finally {
                    $pools->reclaim();
                }
            }
        });
    }
}
