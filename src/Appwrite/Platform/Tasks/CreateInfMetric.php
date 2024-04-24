<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Http\Validator\Text;
use Utopia\Platform\Action;

class CreateInfMetric extends Action
{
    public static function getName(): string
    {
        return 'create-inf-metric';
    }

    public function __construct()
    {

        $this
            ->desc('Create infinity stats metric')
            ->param('after', '', new Text(36), 'After cursor', true)
            ->param('projectId', '', new Text(36), 'Select project to validate', true)
            ->inject('getProjectDB')
            ->inject('dbForConsole')
            ->callback(function (string $after, string $projectId, callable $getProjectDB, Database $dbForConsole) {
                $this->action($after, $projectId, $getProjectDB, $dbForConsole);
            });
    }


    /**
     * @throws Exception
     * @throws Exception\Timeout
     * @throws Exception\Query
     */
    public function action(string $after, string $projectId, callable $getProjectDB, Database $dbForConsole): void
    {

        Console::title('Create infinity metric V1');
        Console::success(APP_NAME . ' Create infinity metric started');

        if (!empty($projectId)) {
            try {
                $project = $dbForConsole->getDocument('projects', $projectId);
                $dbForProject = call_user_func($getProjectDB, $project);
                $this->getUsageData($dbForProject, $project);
            } catch (\Throwable $th) {
                Console::error("Unexpected error occured with Project ID {$projectId}");
                Console::error('[Error] Type: ' . get_class($th));
                Console::error('[Error] Message: ' . $th->getMessage());
                Console::error('[Error] File: ' . $th->getFile());
                Console::error('[Error] Line: ' . $th->getLine());
            }
        } else {
            $queries = [];
            if (!empty($after)) {
                Console::info("Iterating remaining projects after project with ID {$after}");
                $project = $dbForConsole->getDocument('projects', $after);
                $queries = [Query::cursorAfter($project)];
            } else {
                Console::info("Iterating all projects");
            }
            $this->foreachDocument($dbForConsole, 'projects', $queries, function (Document $project) use ($getProjectDB) {
                $projectId = $project->getId();

                try {
                    $dbForProject = call_user_func($getProjectDB, $project);
                    $this->getUsageData($dbForProject, $project);
                } catch (\Throwable $th) {
                    Console::error("Unexpected error occured with Project ID {$projectId}");
                    Console::error('[Error] Type: ' . get_class($th));
                    Console::error('[Error] Message: ' . $th->getMessage());
                    Console::error('[Error] File: ' . $th->getFile());
                    Console::error('[Error] Line: ' . $th->getLine());
                }
            });
        }
    }

    /**
     * @param Database $database
     * @param string $collection
     * @param array $queries
     * @param callable|null $callback
     * @return void
     * @throws Exception
     * @throws Exception\Query
     * @throws Exception\Timeout
     */
    private function foreachDocument(Database $database, string $collection, array $queries = [], callable $callback = null): void
    {
        $limit = 1000;
        $results = [];
        $sum = $limit;
        $latestDocument = null;

        while ($sum === $limit) {
            $newQueries = $queries;

            if ($latestDocument != null) {
                array_unshift($newQueries, Query::cursorAfter($latestDocument));
            }
            $newQueries[] = Query::limit($limit);
            $results = $database->find($collection, $newQueries);

            if (empty($results)) {
                return;
            }

            $sum = count($results);

            foreach ($results as $document) {
                if (is_callable($callback)) {
                    $callback($document);
                }
            }
            $latestDocument = $results[array_key_last($results)];
        }
    }


    /**
     * @param Database $dbForProject
     * @param Document $project
     * @return void
     */
    private function getUsageData(Database $dbForProject, Document $project): void
    {
        try {
            $this->network($dbForProject);
            $this->sessions($dbForProject);
            $this->users($dbForProject);
            $this->teams($dbForProject);
            $this->databases($dbForProject);
            $this->functions($dbForProject);
            $this->storage($dbForProject);
        } catch (\Throwable $th) {
            var_dump($th->getMessage());
        }

        Console::log('Finished project ' . $project->getId() . ' ' . $project->getInternalId());
    }


    /**
     * @param Database $dbForProject
     * @param string $metric
     * @param int|float $value
     * @return void
     * @throws Exception
     * @throws Exception\Authorization
     * @throws Exception\Conflict
     * @throws Exception\Restricted
     * @throws Exception\Structure
     */
    private function createInfMetric(database $dbForProject, string $metric, int|float $value): void
    {

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
        } catch (Duplicate $th) {
            console::log("Error while creating inf metric: duplicate id {$metric}  {$id}");
        }
    }

    /**
     * @param Database $dbForProject
     * @param string $metric
     * @return int|float
     * @throws Exception
     */
    protected function getFromMetric(database $dbForProject, string $metric): int|float
    {

        return  $dbForProject->sum('stats', 'value', [
            Query::equal('metric', [
                $metric,
            ]),
            Query::equal('period', ['1d']),
        ]);
    }

    /**
     * @param Database $dbForProject
     * @throws Exception
     * @throws Exception\Authorization
     * @throws Exception\Conflict
     * @throws Exception\Restricted
     * @throws Exception\Structure
     */
    private function network(database $dbForProject)
    {
        $this->createInfMetric($dbForProject, 'network.inbound', $this->getFromMetric($dbForProject, 'network.inbound'));
        $this->createInfMetric($dbForProject, 'network.outbound', $this->getFromMetric($dbForProject, 'network.outbound'));
        $this->createInfMetric($dbForProject, 'network.requests', $this->getFromMetric($dbForProject, 'network.requests'));
    }


    /**
     * @throws Exception\Authorization
     * @throws Exception\Restricted
     * @throws Exception\Conflict
     * @throws Exception\Timeout
     * @throws Exception\Structure
     * @throws Exception
     * @throws Exception\Query
     */
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


    /**
     * @throws Exception\Authorization
     * @throws Exception\Timeout
     * @throws Exception\Restricted
     * @throws Exception\Structure
     * @throws Exception\Conflict
     * @throws Exception
     * @throws Exception\Query
     */
    private function functions(Database $dbForProject)
    {
        $functionsCount = 0;
        $deploymentsCount = 0;
        $buildsCount = 0;
        $buildsStorageSum = 0;
        $buildsComputeSum = 0;
        $executionsCount = 0;
        $executionsComputeSum = 0;
        $deploymentsStorageSum = 0;

        //functions
        $functions = $dbForProject->find('functions');
        foreach ($functions as $function) {
            //deployments
            $deployments = $dbForProject->find('deployments', [
                Query::equal('resourceType', ['functions']),
                Query::equal('resourceInternalId', [$function->getInternalId()]),
            ]);

            $deploymentCount = 0;
            $deploymentStorageSum = 0;
            foreach ($deployments as $deployment) {
                //builds
                $builds = $dbForProject->count('builds', [
                    Query::equal('deploymentInternalId', [$deployment->getInternalId()]),
                ]);

                $buildsCompute = $dbForProject->sum('builds', 'duration', [
                    Query::equal('deploymentInternalId', [$deployment->getInternalId()]),
                ]);

                $buildsStorage = $dbForProject->sum('builds', 'size', [
                    Query::equal('deploymentInternalId', [$deployment->getInternalId()]),
                ]);

                $this->createInfMetric($dbForProject, $function->getInternalId() . '.builds', $builds);
                $this->createInfMetric($dbForProject, $function->getInternalId() . '.builds.storage', $buildsCompute * 1000);
                $this->createInfMetric($dbForProject, $function->getInternalId() . '.builds.compute', $buildsStorage);

                $buildsCount += $builds;
                $buildsComputeSum += $buildsCompute;
                $buildsStorageSum += $buildsStorage;


                $deploymentCount++;
                $deploymentsCount++;
                $deploymentsStorageSum += $deployment['size'];
                $deploymentStorageSum += $deployment['size'];
            }
            $this->createInfMetric($dbForProject, 'functions.' . $function->getInternalId() . '.deployments', $deploymentCount);
            $this->createInfMetric($dbForProject, 'functions.' . $function->getInternalId() . '.deployments.storage', $deploymentStorageSum);

            //executions
            $executions = $dbForProject->count('executions', [
                Query::equal('functionInternalId', [$function->getInternalId()]),
            ]);

            $executionsCompute = $dbForProject->sum('executions', 'duration', [
                Query::equal('functionInternalId', [$function->getInternalId()]),
            ]);

            $this->createInfMetric($dbForProject, $function->getInternalId() . '.executions', $executions);
            $this->createInfMetric($dbForProject, $function->getInternalId() . '.executions.compute', $executionsCompute * 1000);
            $executionsCount += $executions;
            $executionsComputeSum += $executionsCompute;

            $functionsCount++;
        }

        $this->createInfMetric($dbForProject, 'functions', $functionsCount);
        $this->createInfMetric($dbForProject, 'deployments', $deploymentsCount);
        $this->createInfMetric($dbForProject, 'deployments.storage', $deploymentsStorageSum);
        $this->createInfMetric($dbForProject, 'builds', $buildsCount);
        $this->createInfMetric($dbForProject, 'builds.compute', $buildsComputeSum * 1000);
        $this->createInfMetric($dbForProject, 'builds.storage', $buildsStorageSum);
        $this->createInfMetric($dbForProject, 'executions', $executionsCount);
        $this->createInfMetric($dbForProject, 'executions.compute', $executionsComputeSum * 1000);
    }

    /**
     * @throws Exception\Authorization
     * @throws Exception\Timeout
     * @throws Exception\Structure
     * @throws Exception\Restricted
     * @throws Exception\Conflict
     * @throws Exception
     * @throws Exception\Query
     */
    private function databases(Database $dbForProject)
    {
        $databasesCount = 0;
        $collectionsCount = 0;
        $documentsCount = 0;
        $databases = $dbForProject->find('databases');
        foreach ($databases as $database) {
            $collectionCount = 0;
            $collections = $dbForProject->find('database_' . $database->getInternalId());
            foreach ($collections as $collection) {
                $documents = $dbForProject->count('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());
                $this->createInfMetric($dbForProject, $database->getInternalId() . '.' . $collection->getInternalId() . '.documents', $documents);
                $documentsCount += $documents;
                $collectionCount++;
                $collectionsCount++;
            }
            $this->createInfMetric($dbForProject, $database->getInternalId() . '.collections', $collectionCount);
            $this->createInfMetric($dbForProject, $database->getInternalId() . '.documents', $documentsCount);
            $databasesCount++;
        }
        $this->createInfMetric($dbForProject, 'collections', $collectionsCount);
        $this->createInfMetric($dbForProject, 'databases', $databasesCount);
        $this->createInfMetric($dbForProject, 'documents', $documentsCount);
    }


    /**
     * @throws Exception\Authorization
     * @throws Exception\Structure
     * @throws Exception\Restricted
     * @throws Exception\Conflict
     * @throws Exception
     */
    private function users(Database $dbForProject)
    {
        $users = $dbForProject->count('users');
        $this->createInfMetric($dbForProject, 'users', $users);
    }

    /**
     * @throws Exception\Authorization
     * @throws Exception\Structure
     * @throws Exception\Restricted
     * @throws Exception\Conflict
     * @throws Exception
     */
    private function sessions(Database $dbForProject)
    {
        $users = $dbForProject->count('sessions');
        $this->createInfMetric($dbForProject, 'sessions', $users);
    }

    /**
     * @throws Exception\Authorization
     * @throws Exception\Structure
     * @throws Exception\Restricted
     * @throws Exception\Conflict
     * @throws Exception
     */
    private function teams(Database $dbForProject)
    {
        $teams = $dbForProject->count('teams');
        $this->createInfMetric($dbForProject, 'teams', $teams);
    }
}
