<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class UsageDump extends Action
{
    protected array $stats = [];
    protected array $periods = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00'
    ];

    public static function getName(): string
    {
        return 'usage-dump';
    }

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $this
            ->inject('message')
            ->inject('getProjectDB')
            ->callback(function (Message $message, callable $getProjectDB) {
                $this->action($message, $getProjectDB);
            });
    }

    /**
     * @param Message $message
     * @param callable $getProjectDB
     * @return void
     * @throws Exception
     * @throws \Utopia\Database\Exception
     */
    public function action(Message $message, callable $getProjectDB): void
    {
        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        // TODO: rename both usage workers @shimonewman
        foreach ($payload['stats'] ?? [] as $stats) {
            $project = new Document($stats['project'] ?? []);
            $numberOfKeys = !empty($stats['keys']) ? count($stats['keys']) : 0;
            $receivedAt = $stats['receivedAt'] ?? 'NONE';
            if ($numberOfKeys === 0) {
                continue;
            }

            console::log('[' . DateTime::now() . '] ProjectId [' . $project->getInternalId()  . '] ReceivedAt [' . $receivedAt . '] ' . $numberOfKeys . ' keys');

            try {
                $dbForProject = $getProjectDB($project);
                foreach ($stats['keys'] ?? [] as $key => $value) {
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
                                'region' => System::getEnv('_APP_REGION', 'default'),
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
                console::error('[' . DateTime::now() . '] project [' . $project->getInternalId() . '] database [' . $project['database'] . '] ' . ' ' . $e->getMessage());
            }
        }
    }
}
