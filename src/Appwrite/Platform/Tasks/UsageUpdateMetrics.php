<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Boolean;

class UsageUpdateMetrics extends Action
{
    public static function getName(): string
    {
        return 'usage-update-metrics';
    }

    public function __construct()
    {
        $this
            ->desc('Migrate old usage metric pattern to new')
            ->param('commit', false, new Boolean(true), 'Switch usage metric columns', true)
            ->param('rollback', false, new Boolean(true), 'Rollback  usage metric columns ', true)
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->callback(fn(bool $commit, $rollback, Database $dbForConsole, callable $getProjectDB) => $this->action($commit, $rollback, $dbForConsole, $getProjectDB));
    }


    public function action(bool $commit, $rollback, Database $dbForConsole, callable $getProjectDB): void
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);


        $projects = $dbForConsole->find('projects');
        if (count($projects) === 0) {
            Console::info("Could not found any projects");
            return;
        }

        foreach ($projects as $project) {
            Console::log("Checking Project " . $project->getAttribute('name') . " (" . $project->getInternalId() . ")");
            $dbForProject = $getProjectDB($project);

            if ($commit) {
                try {
                    $dbForProject->renameAttribute('stats', 'metric', 'metricOld');
                    $dbForProject->renameAttribute('stats', 'metricV2', 'metric');
                } catch (\Throwable $th) {
                    Console::Error("Error while trying to rename col: {$th->getMessage()}");
                }

                Console::log("Commit -Renaming stats column metric to metricOld, metricV2 to metric");
                continue;
            }

            if ($rollback) {
                try {
                    $dbForProject->renameAttribute('stats', 'metric', 'metricV2');
                    $dbForProject->renameAttribute('stats', 'metricOld', 'metric');
                } catch (\Throwable $th) {
                    Console::Error("Error while trying to rename column: {$th->getMessage()}");
                }

                Console::log("Rollback - Renaming stats column metric to metricV2, metricOld to metric");
                continue;
            }

            /**
             * Project level
             */
            $metrics[] = ['from' => 'project.$all.network.outbound', 'to' => 'network.outbound'];
            $metrics[] = ['from' => 'project.$all.network.inbound',  'to' => 'network.inbound'];
            $metrics[] = ['from' => 'project.$all.network.requests', 'to' => 'network.requests'];
            $metrics[] = ['from' => 'executions.$all.compute.total', 'to' => 'executions'];
            $metrics[] = ['from' => 'executions.$all.compute.time', 'to' => 'executions.compote'];
            $metrics[] = ['from' => 'builds.$all.compute.total', 'to' => 'builds'];
            $metrics[] = ['from' => 'builds.$all.compute.time', 'to' => 'builds.compute'];
            $metrics[] = ['from' => 'files.$all.storage.size', 'to' => 'files.storage'];
            $metrics[] = ['from' => 'files.$all.count.total', 'to' => 'files'];
            $metrics[] = ['from' => 'buckets.$all.count.total', 'to' => 'buckets'];
            $metrics[] = ['from' => 'collections.$all.count.total', 'to' => 'collections'];
            $metrics[] = ['from' => 'databases.$all.count.total', 'to' => 'databases'];
            $metrics[] = ['from' => 'documents.$all.count.total', 'to' => 'documents'];
            $metrics[] = ['from' => 'users.$all.count.total ', 'to' => 'users'];
            foreach ($metrics as $metric) {
                $stats = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric['from']]),
                    Query::greaterThan('value', 0)
                ]);
                foreach ($stats as $stat) {
                    $stat->setAttribute('metricV2', $metric['to']);
                    $dbForProject->updateDocument('stats', $stat->getId(), $stat);
                }
            }

            /**
             * Buckets, Files
             */
            $buckets = $dbForProject->find('buckets', []);
            foreach ($buckets as $bucket) {
                $metrics = [];

                $metrics[] = [
                    'from' => str_replace('{bucketId}', $bucket->getId(), 'files.{bucketId}.count.total'),
                    'to' => str_replace('{bucketInternalId}', $bucket->getInternalId(), '{bucketInternalId}.files')
                ];

                $metrics[] = [
                    'from' => str_replace('{bucketId}', $bucket->getId(), 'files.{bucketId}.storage.size'),
                    'to' => str_replace('{bucketInternalId}', $bucket->getInternalId(), '{bucketInternalId}.files.storage')
                ];

                foreach ($metrics as $metric) {
                    $stats = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric['from']]),
                    Query::greaterThan('value', 0)
                    ]);
                    foreach ($stats as $stat) {
                        $stat->setAttribute('metricV2', $metric['to']);
                        $dbForProject->updateDocument('stats', $stat->getId(), $stat);
                    }
                }
            }

            /**
             * Databases, collections, documents
             */
            $databases = $dbForProject->find('databases', []);
            foreach ($databases as $database) {
                $metrics = [];
                $metrics[] = [
                    'from' => str_replace('{databaseId}', $database->getId(), 'documents.{databaseId}.count.total'),
                    'to' => str_replace('{databaseInternalId}', $database->getInternalId(), '{databaseInternalId}.collections')];
                $metrics[] = [
                    'from' => str_replace('{databaseId}', $database->getId(), 'documents.{databaseId}.count.total'),
                    'to' => str_replace('{databaseInternalId}', $database->getInternalId(), '{databaseInternalId}.documents')];
                foreach ($metrics as $metric) {
                    $stats = $dbForProject->find('stats', [
                        Query::equal('metric', [$metric['from']]),
                        Query::greaterThan('value', 0)
                    ]);

                    foreach ($stats as $stat) {
                        $stat->setAttribute('metricV2', $metric['to']);
                        $dbForProject->updateDocument('stats', $stat->getId(), $stat);
                    }

                    $collections = $dbForProject->find('database_' . $database->getInternalId(), []);

                    foreach ($collections as $collection) {
                        $__metric = [
                            'from' => str_replace(['{databaseId}','{collectionId}'], [$database->getId(), $collection->getId()], 'documents.{databaseId}/{collectionId}.count.total'),
                            'to' => str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getInternalId(), $collection->getInternalId()], '{databaseInternalId}.{collectionInternalId}.documents')];

                        $__stats = $dbForProject->find('stats', [
                            Query::equal('metric', [$__metric['from']]),
                            Query::greaterThan('value', 0)
                        ]);

                        foreach ($__stats as $__stat) {
                            $__stat->setAttribute('metricV2', $__metric['to']);
                            $dbForProject->updateDocument('stats', $__stat->getId(), $__stat);
                        }
                    }
                }
            }

            /**
             * executions
             */
            $functions = $dbForProject->find('functions', []);
            foreach ($functions as $function) {
                $metrics = [];
                $metrics[] = [
                    'from' => str_replace('{functionId}', $function->getId(), 'executions.{functionId}.compute.time'),
                    'to' => str_replace('{functionInternalId}', $function->getInternalId(), '{functionInternalId}.executions.compute')
                ];
                $metrics[] = [
                    'from' => str_replace('{functionId}', $function->getId(), 'executions.{functionId}.compute.total'),
                    'to' => str_replace('{functionInternalId}', $function->getInternalId(), '{functionInternalId}.executions')
                ];

                $metrics[] = [
                    'from' => str_replace('{functionId}', $function->getId(), 'builds.{functionId}.compute.total'),
                    'to' => str_replace('{functionInternalId}', $function->getInternalId(), '{functionInternalId}.builds')
                ];

                $metrics[] = [
                    'from' => str_replace('{functionId}', $function->getId(), 'builds.{functionId}.compute.time'),
                    'to' => str_replace('{functionInternalId}', $function->getInternalId(), '{functionInternalId}.compute')
                ];
                foreach ($metrics as $metric) {
                    $stats = $dbForProject->find('stats', [
                        Query::equal('metric', [$metric['from']]),
                        Query::greaterThan('value', 0)
                    ]);

                    foreach ($stats as $stat) {
                        $stat->setAttribute('metricV2', $metric['to']);
                        $dbForProject->updateDocument('stats', $stat->getId(), $stat);
                    }
                }
            }
        }
    }
}
