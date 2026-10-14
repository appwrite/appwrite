<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Migration\Destination;
use Utopia\Migration\Destinations\Appwrite as DestinationsAppwrite;
use Utopia\Migration\Destinations\Local;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Appwrite;
use Utopia\Migration\Sources\Firebase;
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Transfer;
use Utopia\Query\OrderDirection;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\IndexType;

/**
 * Migrations CLI Tool
 */
class MigrationCLI
{
    protected Transfer $transfer;

    protected mixed $source;

    protected mixed $destination;

    protected const STRUCTURE = [
        '$collection' => 'databases',
        '$id' => 'collections',
        'name' => 'Collections',
        'attributes' => [
            [
                '$id' => 'databaseInternalId',
                'type' => ColumnType::String->value,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => true,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'databaseId',
                'type' => ColumnType::String->value,
                'signed' => true,
                'size' => Database::LENGTH_KEY,
                'format' => '',
                'filters' => [],
                'required' => true,
                'default' => null,
                'array' => false,
            ],
            [
                '$id' => 'name',
                'type' => ColumnType::String->value,
                'size' => Database::LENGTH_KEY,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'enabled',
                'type' => ColumnType::Boolean->value,
                'signed' => true,
                'size' => 0,
                'format' => '',
                'filters' => [],
                'required' => true,
                'default' => null,
                'array' => false,
            ],
            [
                '$id' => 'documentSecurity',
                'type' => ColumnType::Boolean->value,
                'signed' => true,
                'size' => 0,
                'format' => '',
                'filters' => [],
                'required' => true,
                'default' => null,
                'array' => false,
            ],
            [
                '$id' => 'attributes',
                'type' => ColumnType::String->value,
                'size' => 1000000,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => ['subQueryAttributes'],
            ],
            [
                '$id' => 'indexes',
                'type' => ColumnType::String->value,
                'size' => 1000000,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => ['subQueryIndexes'],
            ],
            [
                '$id' => 'search',
                'type' => ColumnType::String->value,
                'format' => '',
                'size' => 16384,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
        ],
        'indexes' => [
            [
                '$id' => '_fulltext_search',
                'type' => IndexType::Fulltext->value,
                'attributes' => ['search'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => '_key_name',
                'type' => IndexType::Key->value,
                'attributes' => ['name'],
                'lengths' => [Database::LENGTH_KEY],
                'orders' => [OrderDirection::Asc->value],
            ],
            [
                '$id' => '_key_enabled',
                'type' => IndexType::Key->value,
                'attributes' => ['enabled'],
                'lengths' => [],
                'orders' => [OrderDirection::Asc->value],
            ],
            [
                '$id' => '_key_documentSecurity',
                'type' => IndexType::Key->value,
                'attributes' => ['documentSecurity'],
                'lengths' => [],
                'orders' => [OrderDirection::Asc->value],
            ],
        ],
    ];

    /**
     * Prints the current status of migrations as a table after wiping the screen
     */
    public function drawFrame(): void
    {
        echo chr(27) . chr(91) . 'H' . chr(27) . chr(91) . 'J';

        $statusCounters = $this->transfer->getStatusCounters();

        $mask = "| %15.15s | %-7.7s | %10.10s | %7.7s | %7.7s | %8.8s | %8.8s |\n";
        printf($mask, 'Resource', 'Pending', 'Processing', 'Skipped', 'Warning', 'Error', 'Success');
        printf($mask, '-------------', '-------------', '-------------', '-------------', '-------------', '-------------', '-------------');
        foreach ($statusCounters as $resource => $data) {
            printf($mask, $resource, $data['pending'], $data['processing'], $data['skip'], $data['warning'], $data['error'], $data['success']);
        }

        // Render Errors
        $destErrors = $this->destination->getErrors();
        if (!empty($destErrors)) {
            echo "\n\nDestination Errors:\n";
            foreach ($destErrors as $error) {
                /** @var Utopia\Migration\Exception $error */
                echo $error->getResourceName() . '[' . $error->getResourceId() . '] - ' . $error->getMessage() . "\n";
            }
        }

        $sourceErrors = $this->source->getErrors();
        if (!empty($sourceErrors)) {
            echo "\n\nSource Errors:\n";
            foreach ($sourceErrors as $error) {
                /** @var Utopia\Migration\Exception $error */
                echo $error->getResourceGroup() . '[' . $error->getResourceId() . '] - ' . $error->getMessage() . "\n";
            }
        }

        // Render Warnings
        $sourceWarnings = $this->source->getWarnings();
        if (!empty($sourceWarnings)) {
            echo "\n\nSource Warnings:\n";
            foreach ($sourceWarnings as $warning) {
                /** @var Utopia\Migration\Warning $warning */
                echo $warning->getResourceName() . '[' . $warning->getResourceId() . '] - ' . $warning->getMessage() . "\n";
            }
        }

        $destWarnings = $this->destination->getWarnings();
        if (!empty($destWarnings)) {
            echo "\n\nDestination Warnings:\n";
            foreach ($destWarnings as $warning) {
                /** @var Utopia\Migration\Warning $warning */
                echo $warning->getResourceName() . '[' . $warning->getResourceId() . '] - ' . $warning->getMessage() . "\n";
            }
        }
    }

    public function getSource(): Source
    {
        switch ($_ENV['SOURCE_PROVIDER']) {
            case 'appwrite':
                return new Appwrite(
                    $_ENV['SOURCE_APPWRITE_TEST_PROJECT'],
                    $_ENV['SOURCE_APPWRITE_TEST_ENDPOINT'],
                    $_ENV['SOURCE_APPWRITE_TEST_KEY'],
                    Appwrite::SOURCE_DATABASE,
                    $this->getDatabase('source'),
                );
            case 'supabase':
                return new Supabase(
                    $_ENV['SOURCE_SUPABASE_TEST_ENDPOINT'],
                    $_ENV['SOURCE_SUPABASE_TEST_KEY'],
                    $_ENV['SOURCE_SUPABASE_TEST_HOST'],
                    $_ENV['SOURCE_SUPABASE_TEST_DATABASE_NAME'],
                    $_ENV['SOURCE_SUPABASE_TEST_DATABASE_USER'],
                    $_ENV['SOURCE_SUPABASE_TEST_DATABASE_PASSWORD']
                );
            case 'firebase':
                return new Firebase(
                    json_decode(file_get_contents(__DIR__ . '/serviceAccount.json'), true)
                );
            case 'nhost':
                return new NHost(
                    $_ENV['SOURCE_NHOST_TEST_SUBDOMAIN'],
                    $_ENV['SOURCE_NHOST_TEST_REGION'],
                    $_ENV['SOURCE_NHOST_TEST_ADMIN_SECRET'],
                    $_ENV['SOURCE_NHOST_TEST_DATABASE_NAME'],
                    $_ENV['SOURCE_NHOST_TEST_DATABASE_USER'],
                    $_ENV['SOURCE_NHOST_TEST_DATABASE_PASSWORD']
                );
            default:
                throw new Exception('Invalid source provider');
        }
    }

    public function getDestination(): Destination
    {
        switch ($_ENV['DESTINATION_PROVIDER']) {
            case 'appwrite':
                $database = $this->getDatabase('destination');

                return new DestinationsAppwrite(
                    project: $_ENV['DESTINATION_APPWRITE_TEST_PROJECT'],
                    endpoint: $_ENV['DESTINATION_APPWRITE_TEST_ENDPOINT'],
                    key: $_ENV['DESTINATION_APPWRITE_TEST_KEY'],
                    dbForProject: $database,
                    getDatabasesDB: static fn (Document $document): Database => $database,
                    collectionStructure: self::STRUCTURE,
                    dbForPlatform: $database,
                    projectInternalId: $_ENV['DESTINATION_APPWRITE_TEST_PROJECT_INTERNAL_ID'],
                );
            case 'local':
                return new Local('./localBackup');
            default:
                throw new Exception('Invalid destination provider');
        }
    }

    public function getDatabase(string $type): Database
    {
        self::registerFilters();

        $prefix = match ($type) {
            'source' => 'SOURCE_APPWRITE_TEST_',
            'destination' => 'DESTINATION_APPWRITE_TEST_',
            default => throw new Exception('Invalid type for database'),
        };

        $database = new Database(new MariaDB(new PDO(
            $_ENV[$prefix . 'DSN'],
            $_ENV[$prefix . 'USER'],
            $_ENV[$prefix . 'PASSWORD'],
            [
                PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_STRINGIFY_FETCHES => true
            ],
        )), new Cache(new None()));

        $database
            ->setDatabase('appwrite')
            ->setNamespace('_' . $_ENV[$prefix . 'NAMESPACE']);
        $database->getAuthorization()->disable();

        return $database;
    }

    public static function registerFilters(): void
    {
        Database::addFilter(
            'subQueryAttributes',
            function (mixed $value) {
                return;
            },
            function (mixed $value, Document $document, Database $database) {
                $attributes = $database->find('attributes', [
                    Query::equal('collectionInternalId', [$document->getSequence()]),
                    Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                    Query::limit($database->getLimitForAttributes()),
                ]);

                foreach ($attributes as $attribute) {
                    $attributeType = $attribute->getAttribute('type');

                    switch ($attributeType) {
                        case ColumnType::Relationship->value:
                            $options = $attribute->getAttribute('options');
                            foreach ($options as $key => $value) {
                                $attribute->setAttribute($key, $value);
                            }
                            $attribute->removeAttribute('options');
                            break;

                        case ColumnType::String->value:
                            $filters = $attribute->getAttribute('filters', []);
                            $attribute->setAttribute('encrypt', in_array('encrypt', $filters));
                            break;
                    }
                }

                return $attributes;
            }
        );

        Database::addFilter(
            'subQueryIndexes',
            function (mixed $value) {
                return;
            },
            function (mixed $value, Document $document, Database $database) {
                return $database
                    ->find('indexes', [
                        Query::equal('collectionInternalId', [$document->getSequence()]),
                        Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                        Query::limit($database->getLimitForIndexes()),
                    ]);
            }
        );

        Database::addFilter(
            'casting',
            function (mixed $value) {
                return json_encode(['value' => $value], JSON_PRESERVE_ZERO_FRACTION);
            },
            function (mixed $value) {
                if (is_null($value)) {
                    return;
                }

                return json_decode($value, true)['value'];
            }
        );

        Database::addFilter(
            'enum',
            function (mixed $value, Document $attribute) {
                if ($attribute->isSet('elements')) {
                    $attribute->removeAttribute('elements');
                }

                return $value;
            },
            function (mixed $value, Document $attribute) {
                $formatOptions = \json_decode($attribute->getAttribute('formatOptions', '[]'), true);
                if (isset($formatOptions['elements'])) {
                    $attribute->setAttribute('elements', $formatOptions['elements']);
                }

                return $value;
            }
        );

        Database::addFilter(
            'range',
            function (mixed $value, Document $attribute) {
                if ($attribute->isSet('min')) {
                    $attribute->removeAttribute('min');
                }
                if ($attribute->isSet('max')) {
                    $attribute->removeAttribute('max');
                }

                return $value;
            },
            function (mixed $value, Document $attribute) {
                $formatOptions = json_decode($attribute->getAttribute('formatOptions', '[]'), true);
                if (isset($formatOptions['min']) || isset($formatOptions['max'])) {
                    $attribute
                        ->setAttribute('min', $formatOptions['min'])
                        ->setAttribute('max', $formatOptions['max'])
                    ;
                }

                return $value;
            }
        );
    }

    public function start(): void
    {
        $this->loadEnvironment();

        /**
         * Initialise All Source Adapters
         */
        $this->source = $this->getSource();

        // $source->report();

        $this->destination = $this->getDestination();

        /**
         * Initialise Transfer Class
         */
        $this->transfer = new Transfer(
            $this->source,
            $this->destination
        );

        /**
         * Run Transfer
         */
        $this->transfer->run(
            $this->source->getSupportedResources(),
            function () {
                $this->drawFrame();
            }
        );
    }

    protected function loadEnvironment(): void
    {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    }
}

if (\realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $instance = new MigrationCLI();
    $instance->start();
    $instance->drawFrame();
}
