<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

class V23 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        /**
         * Disable SubQueries for Performance.
         */
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryDevKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subQueryVariables', 'subQueryChallenges', 'subQueryProjectVariables', 'subQueryTargets', 'subQueryTopicTargets'] as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::info('Migrating Collections');
        $this->migrateCollections();

        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);

        Console::log('Cleaning Up Collections');
        $this->cleanCollections();
    }

    /**
     * Migrate Collections.
     *
     * @return void
     * @throws Exception|Throwable
     */
    private function migrateCollections(): void
    {
        $internalProjectId = $this->project->getInternalId();
        $collectionType = match ($internalProjectId) {
            'console' => 'console',
            default => 'projects',
        };

        $collections = $this->collections[$collectionType];
        foreach ($collections as $collection) {
            $id = $collection['$id'];

            Console::log("Migrating Collection \"{$id}\"");

            $this->projectDB->setNamespace("_$internalProjectId");

            switch ($id) {
                case '_metadata':
                    $this->createCollection('sites');
                    $this->createCollection('resourceTokens');
                    if ($internalProjectId === 'console') {
                        $this->createCollection('devKeys');
                    }
                    break;
                case 'identities':
                    $attributesToCreate = [
                        'scopes',
                        'expire',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->projectDB, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("$attribute from {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->projectDB->purgeCachedCollection($id);
                    break;
                case 'projects':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'devKeys');
                    } catch (Throwable $th) {
                        Console::warning("'devKeys' from {$id}: {$th->getMessage()}");
                    }
                    $this->projectDB->purgeCachedCollection($id);
                    break;
                case 'rules':
                    $attributesToCreate = [
                        'type',
                        'trigger',
                        'redirectUrl',
                        'redirectStatusCode',
                        'deploymentResourceType',
                        'deploymentId',
                        'deploymentInternalId',
                        'deploymentResourceId',
                        'deploymentResourceInternalId',
                        'deploymentVcsProviderBranch',
                        'search'
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->projectDB, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("$attribute from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToCreate = [
                        '_key_search',
                        '_key_type',
                        '_key_trigger',
                        '_key_deploymentResourceType',
                        '_key_deploymentResourceId',
                        '_key_deploymentResourceInternalId',
                        '_key_deploymentId',
                        '_key_deploymentInternalId',
                        '_key_deploymentVcsProviderBranch',
                    ];
                    foreach ($indexesToCreate as $index) {
                        try {
                            $this->createIndexFromCollection($this->projectDB, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->purgeCachedCollection($id);

                    break;
                case 'memberships':
                    // Create roles index
                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_roles');
                    } catch (Throwable $th) {
                        Console::warning("'_key_roles' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'migrations':
                    $attributesToCreate = [
                        'options',
                        'resourceId',
                        'resourceType'
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->projectDB, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("$attribute from {$id}: {$th->getMessage()}");
                        }
                    }

                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_resource_id');
                    } catch (Throwable $th) {
                        Console::warning("'_key_resource_id' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->purgeCachedCollection($id);
                    break;
                case 'functions':
                    $attributesToCreate = [
                        'deploymentId',
                        'deploymentCreatedAt',
                        'latestDeploymentId',
                        'latestDeploymentInternalId',
                        'latestDeploymentCreatedAt',
                        'latestDeploymentStatus',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->projectDB, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("$attribute from {$id}: {$th->getMessage()}");
                        }
                    }

                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_deploymentId');
                    } catch (Throwable $th) {
                        Console::warning("'_key_deploymentId' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->purgeCachedCollection($id);
                    break;
                case 'deployments':
                    $attributesToCreate = [
                        'buildCommands',
                        'sourcePath',
                        'buildOutput',
                        'adapter',
                        'fallbackFile',
                        'sourceSize',
                        'sourceMetadata',
                        'sourceChunksTotal',
                        'sourceChunksUploaded',
                        'screenshotLight',
                        'screenshotDark',
                        'buildStartedAt',
                        'buildEndedAt',
                        'buildDuration',
                        'buildSize',
                        'status',
                        'buildPath',
                        'buildLogs',
                        'totalSize',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->projectDB, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("$attribute from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToCreate = [
                        '_key_sourceSize',
                        '_key_buildSize',
                        '_key_totalSize',
                        '_key_buildDuration',
                        '_key_type',
                        '_key_status',
                    ];
                    foreach ($indexesToCreate as $index) {
                        try {
                            $this->createIndexFromCollection($this->projectDB, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->purgeCachedCollection($id);
                    break;
                case 'executions':
                    $attributesToCreate = [
                        'resourceInternalId',
                        'resourceId',
                        'resourceType'
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->projectDB, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("$attribute from {$id}: {$th->getMessage()}");
                        }
                    }

                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_resource');
                    } catch (Throwable $th) {
                        Console::warning("'_key_resource' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->purgeCachedCollection($id);
                    break;
                case 'variables':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'secret');
                    } catch (\Throwable $th) {
                        Console::warning("'secret' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->purgeCachedCollection($id);

                    break;
                default:
                    break;
            }

            usleep(50000);
        }
    }

    /**
     * Fix run on each document
     *
     * @param Document $document
     * @return Document
     */
    protected function fixDocument(Document $document): Document
    {
        switch ($document->getCollection()) {
            case 'rules':
                /*
                1. Convert "resourceType" to "type". Convert "function" to "deployment"
                2. Convert "resourceId" to "deploymentResourceId"
                3. Convert "resourceInternalId" to "deploymentResourceInternalId"
                4. Fill "trigger" with "manual"
                5. Fill "deploymentResourceType". If "resourceType" is "function", set "deploymentResourceType" to "function"
                6. Fill "search" with "{$id} {domain}"
                7. Fill "deploymentId" and "deploymentInternalId". If "deploymentResourceType" is "function", get project DB, and find function with ID "resourceId". Then fill rule's "deploymentId" with function's "deployment", and "deploymentId" as backup
                */

                $type = $document->getAttribute('resourceType');
                if ($type === 'function') {
                    $type = 'deployment';
                }

                $deploymentResourceType = null;
                if ($document->getAttribute('resourceType') === 'function') {
                    $deploymentResourceType = 'function';
                }

                $resourceId = $document->getAttribute('resourceId');

                $document
                    ->setAttribute('type', $type)
                    ->setAttribute('deploymentResourceId', $resourceId)
                    ->setAttribute('deploymentResourceInternalId', $document->getAttribute('resourceInternalId'))
                    ->setAttribute('trigger', 'manual')
                    ->setAttribute('deploymentResourceType', $deploymentResourceType)
                    ->setAttribute('search', \implode(" ", [$document->getId(), $document->getAttribute('domain', '')]))
                ;

                if ($deploymentResourceType === 'function') {
                    if ($this->project->getInternalId() !== 'console') {
                        $function = Authorization::skip(fn () => $this->projectDB->getDocument('functions', $resourceId));

                        $document
                            ->setAttribute('deploymentId', $function->getAttribute('deployment', $function->getAttribute('deploymentId', '')))
                            ->setAttribute('deploymentInternalId', $function->getAttribute('deployment', $function->getAttribute('deploymentId', '')))
                        ;
                    }
                }
                break;
            case 'variables':
                /*
                    1. Fill "secret" with "false"
                    */
                $document->setAttribute('secret', false);
                break;
            case 'executions':
                /*
                1. Convert "functionInternalId" to "resourceInternalId"
                2. Convert "functionId" to "resourceId"
                3. Fill "resourceType" with "functions"
                */
                $document
                    ->setAttribute('resourceInternalId', $document->getAttribute('functionInternalId'))
                    ->setAttribute('resourceId', $document->getAttribute('functionId'))
                    ->setAttribute('resourceType', 'functions');
                break;
            case 'migrations':
                /*
                    1. Fill "options" with "[]"
                    */
                $document->setAttribute('options', []);
                break;
            case 'functions':
                /*
                1. Convert "deployment" to "deploymentId"
                --- Fetch activeDeployment from "deploymentId"
                2. Fill "deploymentCreatedAt" with deployment's "$createdAt"
                --- Fetch latestDeployment using find()
                3. Fill latestDeploymentId with latestDeployment's "$id"
                4. Fill latestDeploymentInternalId with latestDeployment's "$internalId"
                5. Fill latestDeploymentCreatedAt with latestDeployment's "$createdAt"
                6. Fill latestDeploymentStatus with latestDeployment's build's "status"
                */

                if ($document->getAttribute('deployment')) {
                    $document->setAttribute('deploymentId', $document->getAttribute('deployment'));
                }

                $deploymentId = $document->getAttribute('deploymentId');
                $deployment = Authorization::skip(fn () => $this->projectDB->getDocument('deployments', $deploymentId));
                $document->setAttribute('deploymentCreatedAt', $deployment->getCreatedAt());

                $latestDeployments = Authorization::skip(fn () => $this->projectDB->find('deployments', [
                    Query::orderDesc(),
                    Query::limit(1),
                    Query::equal('resourceId', [$document->getId()]),
                    Query::equal('resourceType', ['functions']),
                ]));
                $latestDeployment = $latestDeployments[0] ?? new Document();
                $latestBuild = Authorization::skip(fn () => $this->projectDB->getDocument('builds', $latestDeployment->getAttribute('buildId', '')));
                $document
                    ->setAttribute('latestDeploymentId', $latestDeployment->getId())
                    ->setAttribute('latestDeploymentInternalId', $latestDeployment->getInternalId())
                    ->setAttribute('latestDeploymentCreatedAt', $latestDeployment->getCreatedAt())
                    ->setAttribute('latestDeploymentStatus', $latestBuild->getAttribute('status'));
                break;
            case 'deployments':
                /*
                6. Convert "commands" to "buildCommands"
                7. Convert "path" to "sourcePath"
                8. Convert "size" to "sourceSize"
                9. Convert "metadata" to "sourceMetadata"
                10. Convert "chunksTotal" to "sourceChunksTotal"
                11. Convert "chunksUploaded" to "sourceChunksUploaded"
                --- Get build of deployment
                12. Convert build's "startTime" to "buildStartedAt"
                13. Convert build's "endTime" to "buildEndedAt"
                14. Convert build's "duration" to "buildDuration"
                15. Convert build's "size" to "buildSize"
                16. Convert build's "status" to "status"
                17. Convert build's "path" to "buildPath"
                18. Convert build's "logs" to "buildLogs"
                19. Fill "totalSize" with "buildSize" plus "sourceSize"
                */

                $document
                    ->setAttribute('buildCommands', $document->getAttribute('commands'))
                    ->setAttribute('sourcePath', $document->getAttribute('path'))
                    ->setAttribute('sourceSize', $document->getAttribute('size'))
                    ->setAttribute('sourceMetadata', $document->getAttribute('metadata'))
                    ->setAttribute('sourceChunksTotal', $document->getAttribute('chunksTotal'))
                    ->setAttribute('sourceChunksUploaded', $document->getAttribute('chunksUploaded'));

                $build = new Document();
                if (!empty($document->getAttribute('buildId'))) {
                    $build = $this->dbForProject->getDocument('builds', $document->getAttribute('buildId'));
                }

                $document
                    ->setAttribute('buildStartedAt', $build->getAttribute('startTime'))
                    ->setAttribute('buildEndedAt', $build->getAttribute('endTime'))
                    ->setAttribute('buildDuration', $build->getAttribute('duration', 0))
                    ->setAttribute('buildSize', $build->getAttribute('size', 0))
                    ->setAttribute('status', $build->getAttribute('status'))
                    ->setAttribute('buildPath', $build->getAttribute('path', ''))
                    ->setAttribute('buildLogs', $build->getAttribute('logs', ''));

                $totalSize = $document->getAttribute('buildSize', 0)
                    + $document->getAttribute('sourceSize', 0);

                $document->setAttribute('totalSize', $totalSize);
                break;
            case 'migrations':
                /*
                1. Fill "options" with "[]"
                */
                $document->setAttribute('options', []);
                break;
            default:
                break;
        }
        return $document;
    }

    private function cleanCollections(): void
    {
        $projectInternalId = $this->project->getInternalId();

        $collectionType = match ($projectInternalId) {
            'console' => 'console',
            default => 'projects',
        };

        $collections = $this->collections[$collectionType];
        foreach ($collections as $collection) {
            $id = $collection['$id'];

            Console::log("Migrating Collection \"{$id}\"");

            switch ($id) {
                case '_metadata':
                    if ($this->projectDB->exists('builds')) {
                        $this->projectDB->deleteCollection('builds');
                    }
                    break;
                case 'rules':
                    $attributesToDelete = [
                        'resourceId',
                        'resourceInternalId',
                        'resourceType',
                    ];
                    foreach ($attributesToDelete as $attribute) {
                        try {
                            $this->projectDB->deleteAttribute($id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("'$attribute' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToDelete = [
                        '_key_resourceId',
                        '_key_resourceInternalId',
                        '_key_resourceType',
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->projectDB->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->purgeCachedCollection($id);
                    break;
                case 'functions':
                    try {
                        $this->projectDB->deleteAttribute($id, 'deployment');
                    } catch (\Throwable $th) {
                        Console::warning("'deployment' from {$id}: {$th->getMessage()}");
                    }

                    $indexesToDelete = [
                        '_key_deployment'
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->projectDB->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->purgeCachedCollection($id);
                    break;
                case 'deployments':
                    $attributesToDelete = [
                        'buildInternalId',
                        'buildId',
                        'commands',
                        'path',
                        'size',
                        'metadata',
                        'chunksTotal',
                        'chunksUploaded',
                        'search'
                    ];
                    foreach ($attributesToDelete as $attribute) {
                        try {
                            $this->projectDB->deleteAttribute($id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("'$attribute' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToDelete = [
                        '_key_buildId',
                        '_key_size',
                        '_key_search'
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->projectDB->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->purgeCachedCollection($id);
                    break;
                case 'executions':
                    $attributesToDelete = [
                        'functionId',
                        'functionInternalId',
                        'search'
                    ];
                    foreach ($attributesToDelete as $attribute) {
                        try {
                            $this->projectDB->deleteAttribute($id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("'$attribute' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToDelete = [
                        '_key_function',
                        '_fulltext_search'
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->projectDB->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->purgeCachedCollection($id);
                    break;
                default:
                    break;
            }
            usleep(50000);
        }
    }
}
