<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\Query;
use Utopia\System\System;

class V22 extends Migration
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

        Console::info('Migrating collections');
        $this->migrateCollections();

        Console::info('Migrating documents');
        $this->forEachDocument($this->migrateDocument(...));

        Console::info('Cleaning up collections');
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
        $projectInternalId = $this->project->getSequence();

        if (empty($projectInternalId)) {
            throw new Exception('Project ID is null');
        }

        $collectionType = match ($projectInternalId) {
            'console' => 'console',
            default => 'projects',
        };

        $collections = $this->collections[$collectionType];

        foreach ($collections as $collection) {
            $id = $collection['$id'];

            if (empty($id)) {
                continue;
            }

            Console::log("Migrating collection \"{$id}\"");

            switch ($id) {
                case '_metadata':
                    $this->createCollection('sites');
                    $this->createCollection('resourceTokens');
                    if ($projectInternalId === 'console') {
                        $this->createCollection('devKeys');
                    }
                    break;
                case 'identities':
                    $attributes = [
                        'scopes',
                        'expire',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'projects':
                    try {
                        $attributes = [
                            'devKeys',
                        ];
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'rules':
                    $attributes = [
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
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }

                    $indexes = [
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

                    foreach ($indexes as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to create index \"$index\" from {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'memberships':
                    $indexes = [
                        '_key_roles',
                    ];
                    foreach ($indexes as $index) {
                        try {
                            $this->dbForProject->deleteIndex($id, $index);
                        } catch (Throwable $th) {
                            Console::warning("Failed to delete index \"$index\" from {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'migrations':
                    $attributes = [
                        'options',
                        'resourceId',
                        'resourceType'
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }

                    $indexes = [
                        '_key_resource_id',
                    ];

                    foreach ($indexes as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (Throwable $th) {
                            Console::warning("Failed to create index \"$index\" from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'functions':
                    $attributes = [
                        'deploymentId',
                        'deploymentCreatedAt',
                        'latestDeploymentId',
                        'latestDeploymentInternalId',
                        'latestDeploymentCreatedAt',
                        'latestDeploymentStatus',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }

                    $indexes = [
                        '_key_deploymentId',
                    ];

                    foreach ($indexes as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (Throwable $th) {
                            Console::warning("Failed to create index \"$index\" from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'deployments':
                    $attributes = [
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
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }

                    $indexes = [
                        '_key_sourceSize',
                        '_key_buildSize',
                        '_key_totalSize',
                        '_key_buildDuration',
                        '_key_type',
                        '_key_status',
                    ];

                    foreach ($indexes as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to create index \"$index\" from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'executions':
                    $attributes = [
                        'resourceInternalId',
                        'resourceId',
                        'resourceType'
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }

                    $indexes = [
                        '_key_resource',
                    ];
                    foreach ($indexes as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to create index \"$index\" from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'variables':
                    $attributes = [
                        'secret',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Fix run on each document
     *
     * @param Document $document
     * @return Document
     * @throws Conflict
     * @throws Structure
     * @throws Timeout
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Authorization
     * @throws \Utopia\Database\Exception\Query
     */
    private function migrateDocument(Document $document): Document
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
                7. Set "region" to project region
                8. Fill "owner" with "Appwrite" if "domain" ends with "functions" or "sites"
                9. Fill "deploymentId" and "deploymentInternalId". If "deploymentResourceType" is "function", get project DB, and find function with ID "resourceId". Then fill rule's "deploymentId" with function's "deployment", and "deploymentId" as backup
                */

                $deploymentResourceType = null;

                $type = $document->getAttribute('resourceType', $document->getAttribute('type', ''));
                if ($type === 'function') {
                    $type = 'deployment';
                    $deploymentResourceType = 'function';
                }

                $resourceId = $document->getAttribute('resourceId', $document->getAttribute('deploymentResourceId'));
                $resourceInternalId = $document->getAttribute('resourceInternalId', $document->getAttribute('deploymentResourceInternalId'));

                $document
                    ->setAttribute('type', $type)
                    ->setAttribute('trigger', 'manual')
                    ->setAttribute('deploymentResourceId', $resourceId)
                    ->setAttribute('deploymentResourceInternalId', $resourceInternalId)
                    ->setAttribute('deploymentResourceType', $document->getAttribute('deploymentResourceType', $deploymentResourceType))
                    ->setAttribute('search', \implode(' ', [$document->getId(), $document->getAttribute('domain', '')]));

                $project = $this->dbForProject->getDocument('projects', $document->getAttribute('projectId'));

                if ($project->isEmpty()) {
                    Console::warning("Project \"{$document->getAttribute('projectId')}\" not found for rule \"{$document->getId()}\"");
                    $document->setAttribute('region', System::getEnv('_APP_REGION', 'default'));
                    break;
                }

                $document->setAttribute('region', $project->getAttribute('region', System::getEnv('_APP_REGION', 'default')));

                $domain = $document->getAttribute('domain', '');
                $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');
                $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
                $owner = $document->getAttribute('owner', '');
                if (
                    empty($owner) &&
                    (!empty($functionsDomain) && \str_ends_with($domain, $functionsDomain)) ||
                    (!empty($sitesDomain) && \str_ends_with($domain, $sitesDomain))
                ) {
                    $document->setAttribute('owner', 'Appwrite');
                }

                if ($deploymentResourceType === 'function') {
                    $dbForOwnerProject = ($this->getProjectDB)($project);
                    $function = $dbForOwnerProject->getDocument('functions', $resourceId);

                    if ($function->isEmpty()) {
                        Console::warning("Function \"{$resourceId}\" not found for rule \"{$document->getId()}\"");
                        break;
                    }

                    $deploymentId = $function->getAttribute('deployment', $function->getAttribute('deploymentId', $document->getAttribute('deploymentId', '')));
                    $deploymentInternalId = $function->getAttribute('deploymentInternalId', $document->getAttribute('deploymentInternalId', ''));

                    $document
                        ->setAttribute('deploymentId', $deploymentId)
                        ->setAttribute('deploymentInternalId', $deploymentInternalId);
                }
                break;
            case 'variables':
                /*
                1. Fill "secret" with "false"
                */
                $document->setAttribute('secret', $document->getAttribute('secret', false));
                break;
            case 'executions':
                /*
                1. Convert "functionInternalId" to "resourceInternalId"
                2. Convert "functionId" to "resourceId"
                3. Fill "resourceType" with "functions"
                */
                $document
                    ->setAttribute('resourceInternalId', $document->getAttribute('functionInternalId', $document->getAttribute('resourceInternalId')))
                    ->setAttribute('resourceId', $document->getAttribute('functionId', $document->getAttribute('resourceId', '')))
                    ->setAttribute('resourceType', $document->getAttribute('resourceType', 'functions'));
                break;
            case 'functions':
                /*
                1. Convert "deployment" to "deploymentId"
                --- Fetch activeDeployment from "deploymentId"
                2. Fill "deploymentCreatedAt" with deployment's "$createdAt"
                --- Fetch latestDeployment using find()
                3. Fill latestDeploymentId with latestDeployment's "$id"
                4. Fill latestDeploymentInternalId with latestDeployment's "$sequence"
                5. Fill latestDeploymentCreatedAt with latestDeployment's "$createdAt"
                6. Fill latestDeploymentStatus with latestDeployment's build's "status"
                */
                if (empty($document->getAttribute('deployment'))) {
                    break;
                }

                $document->setAttribute('deploymentId', $document->getAttribute('deployment', $document->getAttribute('deploymentId', '')));
                $deploymentId = $document->getAttribute('deploymentId');
                $deployment = $this->dbForProject->getDocument('deployments', $deploymentId);

                if ($deployment->isEmpty()) {
                    Console::warning("Deployment \"{$deploymentId}\" not found for function \"{$document->getId()}\"");
                    break;
                }

                $document->setAttribute('deploymentCreatedAt', $deployment->getCreatedAt());

                $latestDeployment = $this->dbForProject->findOne('deployments', [
                    Query::orderDesc(),
                    Query::equal('resourceId', [$document->getId()]),
                    Query::equal('resourceType', ['functions']),
                ]);

                if ($latestDeployment->isEmpty()) {
                    Console::warning("Latest deployment not found for function \"{$document->getId()}\"");
                    break;
                }

                $latestBuild = $this->dbForProject->getDocument('builds', $latestDeployment->getAttribute('buildId', ''));

                if ($latestBuild->isEmpty()) {
                    Console::warning("Build \"{$latestDeployment->getAttribute('buildId')}\" not found for deployment \"{$latestDeployment->getId()}\"");
                    break;
                }

                $document
                    ->setAttribute('latestDeploymentId', $latestDeployment->getId())
                    ->setAttribute('latestDeploymentInternalId', $latestDeployment->getSequence())
                    ->setAttribute('latestDeploymentCreatedAt', $latestDeployment->getCreatedAt())
                    ->setAttribute('latestDeploymentStatus', $latestBuild->getAttribute('status', $document->getAttribute('latestDeploymentStatus', '')));
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
                    ->setAttribute('buildCommands', $document->getAttribute('commands', $document->getAttribute('buildCommands', '')))
                    ->setAttribute('sourcePath', $document->getAttribute('path', $document->getAttribute('sourcePath', '')))
                    ->setAttribute('sourceSize', $document->getAttribute('size', $document->getAttribute('sourceSize', 0)))
                    ->setAttribute('sourceMetadata', $document->getAttribute('metadata', $document->getAttribute('sourceMetadata', [])))
                    ->setAttribute('sourceChunksTotal', $document->getAttribute('chunksTotal', $document->getAttribute('sourceChunksTotal', 0)))
                    ->setAttribute('sourceChunksUploaded', $document->getAttribute('chunksUploaded', $document->getAttribute('sourceChunksUploaded', 0)));

                $build = new Document();
                if (!empty($document->getAttribute('buildId'))) {
                    $build = $this->dbForProject->getDocument('builds', $document->getAttribute('buildId'));
                }

                $document
                    ->setAttribute('buildStartedAt', $build->getAttribute('startTime', $document->getAttribute('buildStartTime', '')))
                    ->setAttribute('buildEndedAt', $build->getAttribute('endTime', $document->getAttribute('buildEndTime', '')))
                    ->setAttribute('buildDuration', $build->getAttribute('duration', $document->getAttribute('buildDuration', 0)))
                    ->setAttribute('buildSize', $build->getAttribute('size', $document->getAttribute('buildSize', 0)))
                    ->setAttribute('status', $build->getAttribute('status', $document->getAttribute('status', '')))
                    ->setAttribute('buildPath', $build->getAttribute('path', $document->getAttribute('buildPath', '')))
                    ->setAttribute('buildLogs', $build->getAttribute('logs', $document->getAttribute('buildLogs', '')));

                $totalSize = $document->getAttribute('buildSize', 0)
                    + $document->getAttribute('sourceSize', 0);

                $document->setAttribute('totalSize', $totalSize);
                break;
            case 'migrations':
                /*
                1. Fill "options" with "[]"
                */
                $document->setAttribute('options', $document->getAttribute('options', []));
                break;
            default:
                break;
        }
        return $document;
    }

    private function cleanCollections(): void
    {
        $projectInternalId = $this->project->getSequence();

        $collectionType = match ($projectInternalId) {
            'console' => 'console',
            default => 'projects',
        };

        $collections = $this->collections[$collectionType];
        foreach ($collections as $collection) {
            $id = $collection['$id'];

            Console::log("Cleaning up collection \"{$id}\"");

            switch ($id) {
                case '_metadata':
                    if (!$this->dbForProject->getCollection('builds')->isEmpty()) {
                        $this->dbForProject->deleteCollection('builds');
                    }
                    break;
                case 'rules':
                    $attributes = [
                        'resourceId',
                        'resourceInternalId',
                        'resourceType',
                    ];
                    foreach ($attributes as $attribute) {
                        try {
                            $this->dbForProject->deleteAttribute($id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to delete attribute \"$attribute\" from collection {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToDelete = [
                        '_key_resourceId',
                        '_key_resourceInternalId',
                        '_key_resourceType',
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->dbForProject->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to delete index \"$index\" from collection {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'functions':
                    try {
                        $this->dbForProject->deleteAttribute($id, 'deployment');
                    } catch (\Throwable $th) {
                        Console::warning("Failed to delete attribute \"deployment\" from collection {$id}: {$th->getMessage()}");
                    }

                    $indexesToDelete = [
                        '_key_deployment'
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->dbForProject->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to delete index \"$index\" from collection {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'deployments':
                    $attributes = [
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
                    foreach ($attributes as $attribute) {
                        try {
                            $this->dbForProject->deleteAttribute($id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to delete attribute \"$attribute\" from collection {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToDelete = [
                        '_key_buildId',
                        '_key_size',
                        '_key_search'
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->dbForProject->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to delete index \"$index\" from collection {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'executions':
                    $attributes = [
                        'functionId',
                        'functionInternalId',
                        'search'
                    ];
                    foreach ($attributes as $attribute) {
                        try {
                            $this->dbForProject->deleteAttribute($id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to delete attribute \"$attribute\" from collection {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToDelete = [
                        '_key_function',
                        '_fulltext_search'
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->dbForProject->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("Failed to delete index \"$index\" from collection {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                default:
                    break;
            }
        }
    }
}
