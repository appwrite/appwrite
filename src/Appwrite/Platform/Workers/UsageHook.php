<?php

namespace Appwrite\Platform\Workers;

use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
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

    public function action($register, $cache, $pools): void
    {
        Timer::tick(30000, function () use ($register, $cache, $pools) {

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
                    if (!empty($project['keys'])) {
                        $dbForProject->createDocument('statsLogger', new Document([
                            'time' => DateTime::now(),
                            'metrics' => $project['keys'],
                        ]));
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
