<?php

namespace Appwrite\Utopia\Migration\Destinations;

use Appwrite\Event\Usage;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Structure;
use Utopia\Migration\Destination;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Functions\Deployment;
use Utopia\Migration\Resources\Storage\File;
use Utopia\Migration\Transfer;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;


/**
 * Local
 *
 * Used to export data to a local file system or for testing purposes.
 * Exports all data to a single JSON File.
 */
class Backup extends Destination
{
    private array $data = [];

    protected Database $dbForProject;

    protected Device $storage;

    protected Document $backup;

    private Document $project;

    private Document $policy;

    protected Usage $queueForUsage;

    private string $path;

    public function __construct(Document $project, Document $backup, Database $dbForProject, Device $storage, Usage $queueForUsage)
    {
        $this->path = APP_STORAGE_BACKUPS . '/'. $backup->getId();
        $this->dbForProject = $dbForProject;
        $this->queueForUsage = $queueForUsage;
        $this->storage = $storage;
        $this->backup = $backup;
        $this->project = $project;

        $this->policy = $dbForProject->getDocument('backupsPolicy', $this->backup->getId());

        if (! \file_exists($this->path)) {
            mkdir($this->path, 0777, true);
            mkdir($this->path.'/files', 0777, true);
            mkdir($this->path.'/deployments', 0777, true);
        }

    }

    public static function getName(): string
    {
        return 'Backup';
    }

    public static function getSupportedResources(): array
    {
        return [
            Resource::TYPE_ATTRIBUTE,
            Resource::TYPE_BUCKET,
            Resource::TYPE_COLLECTION,
            Resource::TYPE_DATABASE,
            Resource::TYPE_DEPLOYMENT,
            Resource::TYPE_DOCUMENT,
            Resource::TYPE_ENVIRONMENT_VARIABLE,
            Resource::TYPE_FILE,
            Resource::TYPE_FUNCTION,
            Resource::TYPE_HASH,
            Resource::TYPE_INDEX,
            Resource::TYPE_TEAM,
            Resource::TYPE_MEMBERSHIP,
            Resource::TYPE_USER,
        ];
    }

    public function report(array $resources = []): array
    {
        $report = [];

        if (empty($resources)) {
            $resources = $this->getSupportedResources();
        }

        // Check we can write to the file
        if (! \is_writable($this->path. '/backup.json')) {
            $report[Transfer::GROUP_DATABASES][] = 'Unable to write to file: '.$this->path;
            throw new \Exception('Unable to write to file: '. $this->path);
        }

        return $report;
    }

    /**
     * @throws \Exception
     */
    private function sync(): void
    {
        $files = [];

        foreach ($this->data as $group => $v1){
            foreach ($v1 as $k2 => $v2){
                $name = $group . '::' . $k2;
                $files[][] = $name;

                $data = \json_encode($v2);
                if ($data === false) {
                    throw new \Exception('Unable to encode data to JSON, Are you accidentally encoding binary data?');
                }

                \file_put_contents(
                    $this->path . '/'. $name .'.json',
                    \json_encode($data)
                );
            }
        }

        \file_put_contents(
            $this->path . '/index.json',
            \json_encode($files, JSON_PRETTY_PRINT)
        );

        $this->backup->setAttribute('status', 'uploading');
        $this->dbForProject->updateDocument('backups', $this->backup->getId() ,$this->backup);

        $filesize = $this->upload();

        $this->backup
            ->setAttribute('status', 'completed')
            ->setAttribute('finishedAt', DateTime::now())
            ->setAttribute('size', $filesize)
        ;

        if($this->policy->getAttribute('resourceType') === BACKUP_RESOURCE_DATABASE){
            /** Trigger usage queue */
            $this->queueForUsage
                ->setProject($this->project)
                ->addMetric(METRIC_BACKUPS, 1)
                ->addMetric(METRIC_BACKUPS_STORAGE, $filesize)
                ->addMetric(str_replace('{databaseInternalId}', $this->backup['resourceId'], METRIC_DATABASE_ID_BACKUPS), 1)
                ->addMetric(str_replace('{databaseInternalId}', $this->backup['resourceId'], METRIC_DATABASE_ID_BACKUPS_STORAGE), $filesize)
                ->trigger()
            ;
        }
    }

    /**
     * @throws Authorization
     * @throws Structure
     * @throws Conflict
     * @throws Exception|\Exception
     */
    protected function import(array $resources, callable $callback): void
    {

        $this->backup
            ->setAttribute('startedAt', DateTime::now())
            ->setAttribute('status', 'started')
        ;

        $this->dbForProject->updateDocument('backups', $this->backup->getId() ,$this->backup);

        foreach ($resources as $resource) {
            /** @var resource $resource */
            switch ($resource->getName()) {
                case Resource::TYPE_DEPLOYMENT:
                    /** @var Deployment $resource */
                    if ($resource->getStart() === 0) {
                        $this->data[$resource->getGroup()][$resource->getName()][] = $resource->asArray();
                    }

                    file_put_contents($this->path. '/deployments/'.$resource->getId().'.tar.gz', $resource->getData(), FILE_APPEND);
                    $resource->setData('');
                    break;
                case Resource::TYPE_FILE:
                    /** @var File $resource */

                    // Handle folders
                    if (str_contains($resource->getFileName(), '/')) {
                        $folders = explode('/', $resource->getFileName());
                        $folderPath = $this->path. '/files';

                        foreach ($folders as $folder) {
                            $folderPath .= '/'.$folder;

                            if (! \file_exists($folderPath) && str_contains($folder, '.') === false) {
                                mkdir($folderPath, 0777, true);
                            }
                        }
                    }

                    if ($resource->getStart() === 0 && \file_exists($this->path. '/files/'.$resource->getFileName())) {
                        unlink($this->path. '/files/'.$resource->getFileName());
                    }

                    file_put_contents($this->path. '/files/'.$resource->getFileName(), $resource->getData(), FILE_APPEND);
                    $resource->setData('');
                    break;
                default:
                    $this->data[$resource->getGroup()][$resource->getName()][] = $resource->asArray();
                    break;
            }

            $resource->setStatus(Resource::STATUS_SUCCESS);
            $this->cache->update($resource);
            $this->sync();
        }

        $callback($resources);
    }

    /**
     * @throws \Exception
     */
    public function upload(): int
    {
        if (!file_exists($this->path)){
            throw new \Exception('Nothing to upload');
        }

        $id = $this->backup->getInternalId();
        $filename = $id . '.tar.gz';

        $tarFolder = realpath($this->path . '/..');
        $tarFile = $tarFolder . '/' . $filename;

        $local = new Local($this->path);

        Console::error($local->getRoot());

        $local->setTransferChunkSize(5 * 1024 * 1024);  // >= 5MB;

        $cmd = 'cd '. $this->path .' && tar -zcf ' . $tarFile . ' * && cd ' . getcwd();
        Console::error($cmd);

        $stdout = '';
        $stderr = '';
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            throw new \Exception($stderr);
        }

        $destination = $this->storage->getRoot() . '/' . $filename;
        var_dump($this->storage);
        Console::error($destination);

        $result = $local->transfer($tarFile, $destination, $this->storage);
        Console::error($result);

        if ($result === false) {
            throw new \Exception('Error uploading to ' . $destination);
        }

        if (!$this->storage->exists($destination)) {
            throw new \Exception('File not found on cloud: ' . $destination);
        }

        $filesize = filesize($tarFile);

        if (!unlink($tarFile)) {
            Console::error('Error deleting: ' . $tarFile);
            throw new \Exception('Error deleting: ' . $tarFile);
        }

        return $filesize;
    }

}
