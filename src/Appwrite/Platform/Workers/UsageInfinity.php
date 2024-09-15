<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Mail;
use Appwrite\Template\Template;
use Exception;
use phpDocumentor\Reflection\Types\Callable_;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class UsageInfinity extends Action
{


    public static function getName(): string
    {
        return 'usage-infinity';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Usage infinity stats worker')
            ->inject('message')
            ->inject('getProjectDB')
            ->callback(fn (Message $message, Callable $getProjectDB) => $this->action($message, $getProjectDB));
    }

    /**
     * @param Message $message
     * @param Database $dbForConsole
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Callable $getProjectDB): void
    {

        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        var_dump($payload);

        $project = new Document($payload['project']);
        $dbForProject = call_user_func($getProjectDB, $project);

        try {
            $this->storage($dbForProject);
            Console::log('Finished project ' . $project->getId() . ' ' . $project->getInternalId());

        } catch (\Throwable $th) {
            Console::error($th->getMessage());
        }


       }


    private function createInfMetric(database $dbForProject, string $metric, int|float $value): void
    {
         var_dump($metric);
        try {
            $id = \md5("_inf_{$metric}");
            $dbForProject->deleteDocument('stats', $id);
            $dbForProject->createDocument('stats', new Document([
                '$id' => $id,
                'metric' => $metric,
                'period' => 'inf',
                'value'  => (int)$value,
                'time'   => null,
                'region' => 'default',
            ]));
        } catch (\Throwable $th) {
            console::log("Error while creating inf metric:  {$metric}  {$id} " . $th->getMessage());
        }
    }
    private function storage(database $dbForProject)
    {
        $bucketsCount = 0;
        $filesCount = 0;
        $filesStorageSum = 0;

        $buckets = $dbForProject->find('buckets');
        foreach ($buckets as $bucket) {
            $files = $dbForProject->count('bucket_' . $bucket->getInternalId());
            $this->createInfMetric($dbForProject, $bucket->getInternalId() . '.files', $files);

            $filesStorage = $dbForProject->sum('bucket_' . $bucket->getInternalId(), 'sizeOriginal');
            $this->createInfMetric($dbForProject, $bucket->getInternalId() . '.files.storage', $filesStorage);

            $bucketsCount++;
            $filesCount += $files;
            $filesStorageSum += $filesStorage;
        }

        $this->createInfMetric($dbForProject, 'buckets', $bucketsCount);
        $this->createInfMetric($dbForProject, 'files', $filesCount);
        $this->createInfMetric($dbForProject, 'files.storage', $filesStorageSum);
    }
}
