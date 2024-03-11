<?php

namespace Appwrite\Utopia\Migration\Sources;

use Utopia\Database\Database;
use Utopia\Migration\Source;
use Utopia\Storage\Device;


class Restore extends Source
{


    protected Database $dbForProject;

    protected Device $storage;

    public function __construct(Database $dbForProject, Device $storage)
    {

        $this->dbForProject = $dbForProject;
        $this->storage = $storage;
    }

    public static function getName(): string
    {
        return 'Restore';
    }

    /**
     * Export Auth Group
     *
     * @param  int  $batchSize  Max 100
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupAuth(int $batchSize, array $resources)
    {

    }

    /**
     * Export Databases Group
     *
     * @param  int  $batchSize  Max 100
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupDatabases(int $batchSize, array $resources)
    {
    }

    /**
     * Export Storage Group
     *
     * @param  int  $batchSize  Max 5
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupStorage(int $batchSize, array $resources)
    {
    }

    /**
     * Export Functions Group
     *
     * @param  int  $batchSize  Max 100
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupFunctions(int $batchSize, array $resources)
    {
    }

    public static function getSupportedResources(): array
    {
    }


    public function report(array $resources = []): array
    {
    }
}
