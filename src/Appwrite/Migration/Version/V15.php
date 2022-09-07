<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use PDO;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class V15 extends Migration
{
    /**
     * @var \PDO $pdo
     */
    private $pdo;

    public function execute(): void
    {
        global $register;
        $this->pdo = $register->get('db');

        /**
         * Disable SubQueries for Speed.
         */
        foreach (['subQueryAttributes', 'subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships'] as $name) {
            Database::addFilter($name, fn () => null, fn () => []);
        }

        Console::log('Migrating project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        Console::info('Migrating Collections');
        $this->migrateCollections();
        // Console::info('Migrating Documents');
        // $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Returns all columns from the Table.
     * @param string $table
     * @return array
     * @throws \Exception
     * @throws \PDOException
     */
    protected function getSQLColumnTypes(string $table): array
    {
        $query = $this->pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '_{$this->project->getInternalId()}_{$table}' AND table_schema = '{$this->projectDB->getDefaultDatabase()}'");
        $query->execute();

        return array_reduce($query->fetchAll(), function (array $carry, array $item) {
            $carry[$item['COLUMN_NAME']] = $item['DATA_TYPE'];

            return $carry;
        }, []);
    }

    /**
     * Migrates all Integer colums for timestamps to DateTime
     * @return void
     * @throws \Exception
     */
    protected function migrateDateTimeAttribute(string $table, string $attribute): void
    {
        $columns = $this->getSQLColumnTypes($table);

        if ($columns[$attribute] === 'int') {
            try {
                $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$table}` MODIFY {$attribute} VARCHAR(64)")->execute();
                $this->pdo->prepare("UPDATE `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$table}` SET {$attribute} = FROM_UNIXTIME({$attribute})")->execute();
            } catch (\Throwable $th) {
                Console::warning($th->getMessage());
            }
        }

        if ($columns[$attribute] === 'varchar') {
            try {
                $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$table}` MODIFY {$attribute} DATETIME(3)")->execute();
            } catch (\Throwable $th) {
                Console::warning($th->getMessage());
            }
        }
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     */
    protected function migrateCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            Console::log("- {$id}");

            $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

            switch ($id) {
                case '_metadata':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'abuse':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'attributes':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                case 'audit':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'time');
                    break;
                case 'buckets':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'builds':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'certificates':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'databases':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'deployments':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'domains':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'executions':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'functions':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'indexes':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'keys':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'memberships':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'platforms':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'projects':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'realtime':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'sessions':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'stats':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'teams':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'tokens':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'users':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;
                case 'webhooks':
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;

                case 'files':

                    break;
                case 'collections':

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
     * @param \Utopia\Database\Document $document
     * @return \Utopia\Database\Document
     */
    protected function fixDocument(Document $document)
    {
        switch ($document->getCollection()) {
            case 'projects':
                /**
                 * Bump Project version number.
                 */
                $document->setAttribute('version', '1.0.0-RC.1');

                if (!empty($document->getAttribute('teamId')) && is_null($document->getAttribute('teamInternalId'))) {
                    $internalId = $this->projectDB->getDocument('teams', $document->getAttribute('teamId'))->getInternalId();
                    $document->setAttribute('teamInternalId', $internalId);
                }

                break;
            case 'keys':
                /**
                 * Add new 'expire' attribute and default to never (0).
                 */
                if (is_null($document->getAttribute('expire'))) {
                    $document->setAttribute('expire', 0);
                }
                /**
                 * Add Internal ID 'projectId' for Subqueries.
                 */
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'audit':
                /**
                 * Add Database Layer to collection resource.
                 */
                if (str_starts_with($document->getAttribute('resource'), 'collection/')) {
                    $document
                        ->setAttribute('resource', "database/default/{$document->getAttribute('resource')}")
                        ->setAttribute('event', "databases.default.{$document->getAttribute('event')}");
                }

                if (str_starts_with($document->getAttribute('resource'), 'document/')) {
                    $collectionId = explode('.', $document->getAttribute('event'))[1];
                    $document
                        ->setAttribute('resource', "database/default/collection/{$collectionId}/{$document->getAttribute('resource')}")
                        ->setAttribute('event', "databases.default.{$document->getAttribute('event')}");
                }

                break;
            case 'stats':
                /**
                 * Add Database Layer to stats metric.
                 */
                if (str_starts_with($document->getAttribute('metric'), 'database.')) {
                    $metric = ltrim($document->getAttribute('metric'), 'database.');
                    $document->setAttribute('metric', "databases.default.{$metric}");
                }

                break;
            case 'webhooks':
                /**
                 * Add new 'signatureKey' attribute and generate a random value.
                 */
                if (empty($document->getAttribute('signatureKey'))) {
                    $document->setAttribute('signatureKey', \bin2hex(\random_bytes(64)));
                }
                /**
                 * Add Internal ID 'projectId' for Subqueries.
                 */
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'domains':
                /**
                 * Add Internal ID 'projectId' for Subqueries.
                 */
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'tokens':
            case 'sessions':
                /**
                 * Add Internal ID 'userId' for Subqueries.
                 */
                if (!empty($document->getAttribute('userId')) && is_null($document->getAttribute('userInternalId'))) {
                    $internalId = $this->projectDB->getDocument('users', $document->getAttribute('userId'))->getInternalId();
                    $document->setAttribute('userInternalId', $internalId);
                }

                break;
            case 'memberships':
                /**
                 * Add Internal ID 'userId' for Subqueries.
                 */
                if (!empty($document->getAttribute('userId')) && is_null($document->getAttribute('userInternalId'))) {
                    $internalId = $this->projectDB->getDocument('users', $document->getAttribute('userId'))->getInternalId();
                    $document->setAttribute('userInternalId', $internalId);
                }
                /**
                 * Add Internal ID 'teamId' for Subqueries.
                 */
                if (!empty($document->getAttribute('teamId')) && is_null($document->getAttribute('teamInternalId'))) {
                    $internalId = $this->projectDB->getDocument('teams', $document->getAttribute('teamId'))->getInternalId();
                    $document->setAttribute('teamInternalId', $internalId);
                }

                break;
            case 'platforms':
                /**
                 * Migrate dateCreated to $createdAt.
                 */
                if (empty($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                /**
                 * Migrate dateUpdated to $updatedAt.
                 */
                if (empty($document->getUpdatedAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }
                /**
                 * Add Internal ID 'projectId' for Subqueries.
                 */
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'buckets':
                /**
                 * Migrate dateCreated to $createdAt.
                 */
                if (empty($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                /**
                 * Migrate dateUpdated to $updatedAt.
                 */
                if (empty($document->getUpdatedAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }

                /**
                 * Migrate all Storage Buckets to use Internal ID.
                 */
                $internalId = $this->projectDB->getDocument('buckets', $document->getId())->getInternalId();

                /**
                 * Migrate all Storage Bucket Files.
                 */

                break;
            case 'users':
                /**
                 * Set 'phoneVerification' to false if not set.
                 */
                if (is_null($document->getAttribute('phoneVerification'))) {
                    $document->setAttribute('phoneVerification', false);
                }

                break;
            case 'functions':
                /**
                 * Migrate dateCreated to $createdAt.
                 */
                if (empty($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                /**
                 * Migrate dateUpdated to $updatedAt.
                 */
                if (empty($document->getUpdatedAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }

                break;
            case 'deployments':
            case 'executions':
            case 'teams':
                /**
                 * Migrate dateCreated to $createdAt.
                 */
                if (empty($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }

                break;
        }

        return $document;
    }
}
