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

        $project = new Document($project);

        /** Create a database dump of all the tables in this project database */
        switch ($type) {
            case 'backup': 
                $this->backup($project, $backupId);
                break;
            case 'restore':
                $this->restore($project, $backupId);
                break;
            default:
                Console::error('Unknown backup type: ' . $type);
                break;
        }
    }

    private function backup(Document $project, string $backupId)
    {
        try {
            $dbForConsole = $this->getConsoleDB();

            /** Update the backup state */
            $backup = $dbForConsole->getDocument('backups', $backupId);
            if ($backup->isEmpty()) {
                Console::error('Backup not found: ' . $backupId);
                return;
            }

            $backup->setAttribute('status', 'processing');
            $dbForConsole->updateDocument('backups', $backupId, $backup);

            /** Create list of tables to backup */
            $dbForProject = $this->getProjectDB($project->getId(), $project);
            $tables = $dbForProject->listCollections();
            $tablesToBackup = [];
            foreach ($tables as $table) {
                if ($table->getId() === 'backups') {
                    continue;
                }
                $tablesToBackup[] = $dbForProject->getNamespace() . '_' .$table->getId();
            }

            /**
             * Create Temporary Backup File
             */
            $sqldumpFilename = $backupId . '.sql';
            $compressedFilename = $backupId . '.tar.gz';
            $tmpSqlDump = "/tmp/backups/$backupId/$sqldumpFilename";
            $tmpBackup = "/tmp/backups/$backupId/$compressedFilename";
            if (!\file_exists(\dirname($tmpBackup))) {
                if (!@\mkdir(\dirname($tmpBackup), 0755, true)) {
                    throw new Exception("Failed to create temporary directory", 500);
                }
            }

            $deviceBackups = $this->getBackupsDevice($project->getId());
            $path = $deviceBackups->getPath($compressedFilename);

            $command = 'mysqldump -alv -h' . App::getEnv('_APP_DB_HOST') . ' -u' . App::getEnv('_APP_DB_USER') . ' -p' . App::getEnv('_APP_DB_PASS') . ' ' . App::getEnv('_APP_DB_SCHEMA') . ' ' . implode(' ', $tablesToBackup) . ' > ' . $tmpSqlDump;

            Console::info('Running command: ' . $command);
            $stdout = '';
            $stderr = '';
            $stdin = '';
            $return = Console::execute($command, $stdin, $stdout, $stderr);

            if ($return !== 0) {
                throw new Exception('Failed to create backup: ' . $backupId);
            }

            /** Compress the SQL dump file */
            $command = 'tar -czf ' . $tmpBackup . ' -C /tmp/backups/' . $backupId . ' ' . $sqldumpFilename;
            Console::info('Running command: ' . $command);
            $return = Console::execute($command, $stdin, $stdout, $stderr);
            if ($return !== 0) {
                throw new Exception('Failed to compress backup: ' . $backupId);
            }

            /** Zip the Backup file and move it to the path */
            $return = $deviceBackups->move($tmpBackup, $path);
            if ($return === false) {
                throw new Exception('Failed to move backup: ' . $backupId);
            }

            $backup->setAttribute('path', $path);
            $backup->setAttribute('status', 'success');
            $dbForConsole->updateDocument('backups', $backup->getId(), $backup);

        } catch (Exception $e) {
            Console::error($e->getMessage());
            $backup->setAttribute('status', 'failed');
            $dbForConsole->updateDocument('backups', $backup->getId(), $backup);
        }
        
    }

    private function restore(Document $project, string $backupId) {
        /** Write a function to read the backup and restore it */
        try {
            $dbForConsole = $this->getConsoleDB();

            /** Update the backup state */
            $backup = $dbForConsole->getDocument('backups', $backupId);
            if ($backup->isEmpty()) {
                Console::error('Backup not found: ' . $backupId);
                return;
            }

            /** load the backup file */
            $deviceBackups = $this->getBackupsDevice($project->getId());
        } catch (Exception $e) {
            Console::error($e->getMessage());
        }
    }

    public function shutdown(): void
    {
    }
}
