<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;

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
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('register')
            ->callback(function (Group $pools, Cache $cache, Database $dbForConsole, Registry $register) {
                $this->action($pools, $cache, $dbForConsole, $register);
            });
    }


    public function action(Group $pools, Cache $cache, Database $dbForConsole, Registry $register): void
    {

        Console::title('Create infinity metric V1');
        Console::success(APP_NAME . ' Create infinity metric started');


        /* Initialise new Utopia app */
        $app = new App('UTC');
        $console = $app->getResource('console');
        $projects = [$console];

        /** Database connections */
        $totalProjects = $dbForConsole->count('projects');
        Console::success("Found a total of: {$totalProjects} projects");

        $count = 0;
        $limit = 30;
        $sum = 30;
        $offset = 0;
        while (!empty($projects)) {
            foreach ($projects as $project) {

                /**
                 * Skip user projects with id 'console'
                 */
                if ($project->getId() === 'console') {
                    continue;
                }

                try {
                    $db = $project->getAttribute('database');
                    $adapter = $pools
                        ->get($db)
                        ->pop()
                        ->getResource();

                    $dbForProject = new Database($adapter, $cache);
                    $dbForProject->setDefaultDatabase('appwrite');
                    $dbForProject->setNamespace('_' . $project->getInternalId());

                    $this->network($dbForProject);
                    $this->sessions($dbForProject);
                    $this->users($dbForProject);
                    $this->teams($dbForProject);
                    $this->databases($dbForProject);
                    $this->functions($dbForProject);
                    $this->storage($dbForProject);
                } catch (\Throwable $th) {
                    var_dump($th->getMessage());
                } finally {
                    $pools
                        ->get($db)
                        ->reclaim();
                }

                Console::log('Finished project ' . $project->getId() . ' ' . $project->getInternalId());
            }

            $sum = \count($projects);

            $projects = $dbForConsole->find('projects', [
                Query::limit($limit),
                Query::offset($offset),
            ]);

            $offset = $offset + $limit;
            $count = $count + $sum;
        }

        Console::log('Iterated through ' . $count - 1 . '/' . $totalProjects . ' projects');
    }

    /**
     * @param string $metric
     * @param int|float $value
     * @return void
     */
    private function createInfMetric(database $dbForProject, string $metric, int|float $value): void
    {

        try {
            $id = \md5("_inf_{$metric}");

            $dbForProject->deleteDocument('stats_v2', $id);

            $dbForProject->createDocument('stats_v2', new Document([
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

        return  $dbForProject->sum('stats_v2', 'value', [
            Query::equal('metric', [
                $metric,
            ]),
            Query::equal('period', ['1d']),
        ]);
    }

    /**
     * @throws Exception
     */
    private function network(database $dbForProject)
    {
        $this->createInfMetric($dbForProject, 'network.inbound', $this->getFromMetric($dbForProject, 'network.inbound'));
        $this->createInfMetric($dbForProject, 'network.outbound', $this->getFromMetric($dbForProject, 'network.outbound'));
        $this->createInfMetric($dbForProject, 'network.requests', $this->getFromMetric($dbForProject, 'network.requests'));
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


    private function users(Database $dbForProject)
    {
        $users = $dbForProject->count('users');
        $this->createInfMetric($dbForProject, 'users', $users);
    }

    private function sessions(Database $dbForProject)
    {
        $users = $dbForProject->count('sessions');
        $this->createInfMetric($dbForProject, 'sessions', $users);
    }

    private function teams(Database $dbForProject)
    {
        $teams = $dbForProject->count('teams');
        $this->createInfMetric($dbForProject, 'teams', $teams);
    }
}
