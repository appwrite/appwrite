<?php

use Appwrite\Event\Event;
use Appwrite\Resque\Worker;
use Utopia\Audit\Audit;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;

require_once __DIR__ . '/../init.php';

Console::title('Backups V1 Worker');
Console::success(APP_NAME . ' backups worker v1 has started');

class BackupsV1 extends Worker
{
    public function getName(): string
    {
        return "backups";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $project = $this->args['project'];
        $type = $this->args['payload']['type'];
        $backupId = $this->args['payload']['backupId'];

        /** Create a database dump of all the tables in this project database */
        switch ($type) {
            case 'backup': 
                $this->backup($project, $backupId);
                break;
            // case 'restore':
            //     $this->restore($project, $backupId);
            //     break;
            default:
                Console::error('Unknown backup type: ' . $type);
                break;
        }
    }

    private function backup(Document $project, string $backupId)
    {
        $dbForProject = $this->getProjectDB($project->getId(), $project);

        /** Update the backup state */
        $backup = $dbForProject->getDocument('backups', $backupId);
        if ($backup->isEmpty()) {
            Console::error('Backup not found: ' . $backupId);
            return;
        }
        $backup->setAttribute('status', 'processing');
        $dbForProject->updateDocument('backups', $backupId, $backup);


        /** Start the backup process */
        $tables = $dbForProject->listCollections();
        $tablesToBackup = [];
        foreach ($tables as $table) {
            if ($table->getId() === 'backups') {
                continue;
            }

            $tablesToBackup[] = $table->getId();
        }

        $backupPath = APP_STORAGE_BACKUPS . '/' . $project . '/' . $backupId . '.tar.gz';

        $command = 'mysqldump -h ' . App::getEnv('_APP_DB_HOST') . ' -u ' . App::getEnv('_APP_DB_USER') . ' -p' . App::getEnv('_APP_DB_PASS') . ' ' . App::getEnv('_APP_DB_SCHEMA') . ' ' . implode(' ', $tablesToBackup) . ' | gzip > ' . escapeshellarg($backupPath);

        Console::info('Running command: ' . $command);

        // exec($command, $output, $return);

        // if ($return !== 0) {
        //     Console::error('Failed to create backup: ' . $backupId);
        //     return;
        // }

        // $backup->setAttribute('status', 'created');
        // $dbForInternal->updateDocument('backups', $backup);
    }

    public function shutdown(): void
    {
    }
}
