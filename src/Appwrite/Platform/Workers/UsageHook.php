<?php

namespace Appwrite\Platform\Workers;

use Utopia\App;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Swoole\Timer;
use Utopia\Database\DateTime;

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
            ->inject('getProjectDB')
            ->callback(function ($register, callable $getProjectDB) {
                $this->action($register, $getProjectDB);
            })
        ;
    }

    /**
     * @param $register
     * @param $getProjectDB
     * @return void
     */
    public function action($register, $getProjectDB): void
    {

        $interval = (int) App::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '60000');
        Timer::tick($interval, function () use ($register, $getProjectDB) {

            $offset = count(self::$stats);
            $projects = array_slice(self::$stats, 0, $offset, true);
            array_splice(self::$stats, 0, $offset);
            foreach ($projects as $data) {
                $numberOfKeys = !empty($data['keys']) ? count($data['keys']) : 0;
                console::log(DateTime::now() . ' Iterating over ' . $numberOfKeys . ' keys');

                if ($numberOfKeys === 0) {
                    continue;
                }

                $projectInternalId = $data['project']->getInternalId();

                try {
                    $dbForProject = $getProjectDB($data['project']);

                    foreach ($data['keys'] ?? [] as $key => $value) {
                        if ($value == 0) {
                            continue;
                        }

                        foreach ($this->periods as $period => $format) {
                            $time = 'inf' === $period ? null : date($format, time());
                            $id = \md5("{$time}_{$period}_{$key}");

                            try {
                                $dbForProject->createDocument('stats_v2', new Document([
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
                                        'stats_v2',
                                        $id,
                                        'value',
                                        abs($value)
                                    );
                                } else {
                                    $dbForProject->increaseDocumentAttribute(
                                        'stats_v2',
                                        $id,
                                        'value',
                                        $value
                                    );
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    console::error(DateTime::now() . ' ' . $projectInternalId . ' ' . $e->getMessage());
                }
            }
        });
    }
}
