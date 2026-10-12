<?php

namespace Utopia\Migration\Destinations;

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\Adapter;
use Appwrite\Enums\BuildRuntime;
use Appwrite\Enums\Compression;
use Appwrite\Enums\Framework;
use Appwrite\Enums\PasswordHash;
use Appwrite\Enums\ProjectProtocolId;
use Appwrite\Enums\ProxyResourceType;
use Appwrite\Enums\Runtime;
use Appwrite\Enums\SmtpEncryption;
use Appwrite\Enums\StatusCode;
use Appwrite\InputFile;
use Appwrite\Services\Functions;
use Appwrite\Services\Messaging;
use Appwrite\Services\Project;
use Appwrite\Services\Proxy;
use Appwrite\Services\Sites;
use Appwrite\Services\Storage;
use Appwrite\Services\Teams;
use Appwrite\Services\Users;
use Override;
use Utopia\Database\Attribute as UtopiaAttribute;
use Utopia\Database\AttributeUpdate;
use Utopia\Database\Capability;
use Utopia\Database\Collection as UtopiaCollection;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\DateTime;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Format;
use Utopia\Database\Id;
use Utopia\Database\Index as UtopiaIndex;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Relationship as UtopiaRelationship;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipSide;
use Utopia\Database\RelationshipUpdate;
use Utopia\Database\Role;
use Utopia\Database\Validator\IndexDefinition as IndexValidator;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\UID;
use Utopia\Migration\Destination;
use Utopia\Migration\Destinations\Appwrite\ProvisioningOwner;
use Utopia\Migration\Exception;
use Utopia\Migration\Exception\Finalization;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Auth\AuthMethods;
use Utopia\Migration\Resources\Auth\Hash;
use Utopia\Migration\Resources\Auth\Membership;
use Utopia\Migration\Resources\Auth\OAuth2\OAuth2Provider;
use Utopia\Migration\Resources\Auth\Policies;
use Utopia\Migration\Resources\Auth\Team;
use Utopia\Migration\Resources\Auth\User;
use Utopia\Migration\Resources\Database\Attribute;
use Utopia\Migration\Resources\Database\Collection as CollectionResource;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Index;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Resources\Domains\Rule;
use Utopia\Migration\Resources\Functions\Deployment;
use Utopia\Migration\Resources\Functions\EnvVar;
use Utopia\Migration\Resources\Functions\Func;
use Utopia\Migration\Resources\Integrations\ApiKey;
use Utopia\Migration\Resources\Integrations\Platform;
use Utopia\Migration\Resources\Messaging\Message;
use Utopia\Migration\Resources\Messaging\Provider;
use Utopia\Migration\Resources\Messaging\Subscriber;
use Utopia\Migration\Resources\Messaging\Topic;
use Utopia\Migration\Resources\Settings\Labels;
use Utopia\Migration\Resources\Settings\ProjectVariable;
use Utopia\Migration\Resources\Settings\Protocols;
use Utopia\Migration\Resources\Settings\Services as ServicesResource;
use Utopia\Migration\Resources\Settings\SMTP;
use Utopia\Migration\Resources\Settings\Webhook;
use Utopia\Migration\Resources\Sites\Deployment as SiteDeployment;
use Utopia\Migration\Resources\Sites\EnvVar as SiteEnvVar;
use Utopia\Migration\Resources\Sites\Site;
use Utopia\Migration\Resources\Storage\Bucket;
use Utopia\Migration\Resources\Storage\File;
use Utopia\Migration\Resources\Templates\EmailTemplate;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;

/**
 * @phpstan-type CollectionStructure array{
 *     '$collection'?: string,
 *     '$id'?: string,
 *     name?: string,
 *     attributes?: list<UtopiaAttribute|UtopiaDocument|array<string, mixed>>,
 *     indexes?: list<UtopiaIndex|UtopiaDocument|array<string, mixed>>,
 *     ...
 * }
 */
class Appwrite extends Destination
{
    /** Names of the project-DB collections holding Appwrite schema metadata. */
    private const META_DATABASES = 'databases';
    private const META_ATTRIBUTES = 'attributes';
    private const META_INDEXES = 'indexes';

    private const VECTORSDB_EMBEDDINGS_KEY = 'embeddings';

    /**
     * Variable keys become environment variable names at build and runtime, so
     * the API only accepts C-style identifiers. Variables are written straight
     * to the collection here, which skips that check, and a source project can
     * still hold a key stored before the rule existed.
     */
    private const VARIABLE_KEY_PATTERN = '/^[A-Za-z_]\w*$/D';

    /** A database is provisioning while its resources transfer, ready once the run completes, or failed if creation errored. */
    private const DATABASE_STATUS_PROVISIONING = 'provisioning';
    private const DATABASE_STATUS_READY = 'ready';
    private const DATABASE_STATUS_FAILED = 'failed';

    /** Attributes naming the migration attempt that provisions a database; a destination schema gains them after `status`. */
    private const OWNER_MIGRATION_ID = 'migrationId';
    private const OWNER_ATTEMPT_ID = 'migrationAttemptId';

    /** 24 hours, the longest lease a live Appwrite attempt holds. */
    public const int DEFAULT_PROVISIONING_LEASE = 86_400;

    /**
     * Attribute fields the SDK can't update in place (no per-type updateX endpoint exposes them); a change here
     * forces drop+recreate. The type is immutable too, but is compared through {@see self::typeMatches()}.
     */
    private const ATTRIBUTE_IMMUTABLE_FIELDS = [
        'array',
        'signed',
        'format',
        'formatOptions',
        'filters',
    ];

    /** Relationship options fields the SDK can't update in place (only onDelete/newKey are SDK-reachable); a change here forces drop+recreate. */
    private const RELATIONSHIP_IMMUTABLE_FIELDS = [
        'relationType',
        'twoWay',
        'twoWayKey',
        'relatedCollection',
    ];

    protected Client $client;
    protected string $projectId;

    protected string $key;

    private Functions $functions;
    private Messaging $messaging;
    private Project $project;
    private Proxy $proxy;
    private Sites $sites;
    private Storage $storage;
    private Teams $teams;
    private Users $users;

    /**
     * @var callable(UtopiaDocument $database): UtopiaDatabase
    */
    protected $getDatabasesDB;

    /**
     * Resolves the DSN written into the destination's `_databases.database`
     * for a migrated database. When the source and destination projects don't
     * share the same DSN — e.g. one project is on a host the other isn't —
     * pass a resolver so the destination metadata carries its own DSN instead
     * of the source's. When unset, the attribute is left blank and the
     * runtime falls back to the destination project's DSN at read time, which
     * is safe for single-host single-type setups.
     *
     * @var (callable(Database $resource): string)|null
     */
    protected $getDatabaseDSN;

    /** Immutable owner written with every provisioning transition initiated by this destination. */
    private readonly ProvisioningOwner $owner;

    /**
     * Resolves the authoritative terminal owner of an incomplete database that
     * names one. Database status is resource-local and never proves the overall
     * migration attempt terminal. Callers must derive this from their authoritative
     * operation lifecycle; null means active or unknown and fails closed.
     *
     * @var callable(UtopiaDocument $database): ?ProvisioningOwner
     */
    private $getRecoverableOwner;

    /**
     * @var array<UtopiaDocument>
     */
    private array $rowBuffer = [];

    /**
     * Overwrite-mode orphan tracking, keyed by (database, table). Orphans are
     * destination keys not in `attributeKeys` / `indexKeys`. Entries removed
     * after their cleanup runs so the end-of-run sweep only visits tables
     * that had no rows.
     *
     * @var array<string, array{
     *   database: UtopiaDocument,
     *   table: UtopiaDocument,
     *   dbForDatabases: UtopiaDatabase,
     *   attributeKeys: list<string>,
     *   indexKeys: list<string>,
     * }>
     */
    private array $orphansByTable = [];

    /**
     * Two-way pairs already reconciled this run; partner pass short-circuits.
     *
     * @var array<string, true>
     */
    private array $processedTwoWayPairs = [];

    /**
     * Databases created this run, held in `provisioning` until success() flips
     * each to `ready`. Keyed by database id.
     *
     * @var array<string, true>
     */
    private array $provisioningDatabases = [];

    private bool $finalizationPending = false;

    /** Whether the destination project's database metadata supports lifecycle status. */
    private ?bool $databaseStatusSupported = null;

    /** Whether the destination project's database metadata can record which migration attempt provisions a database. */
    private ?bool $provisioningOwnerSupported = null;

    /**
     * @param string $project
     * @param string $endpoint
     * @param string $key
     * @param UtopiaDatabase $dbForProject
     * @param callable(UtopiaDocument $database):UtopiaDatabase $getDatabasesDB
     * @param CollectionStructure $collectionStructure
     * @param ProvisioningOwner $owner Immutable logical migration and execution-attempt identifiers for databases provisioned by this destination.
     * @param callable(UtopiaDocument $database): ?ProvisioningOwner $getRecoverableOwner Returns the exact authoritative terminal owner for an existing `provisioning` or `failed` database that names an owner. Resource status alone is not lifecycle proof; return null while the owning migration attempt is active or unknown. A database naming no owner predates ownership and is recovered without it.
     * @param OnDuplicate $onDuplicate Behavior when a row with an existing $id is encountered.
     * @param (callable(Database $resource): string)|null $getDatabaseDSN Resolver for the destination's `_databases.database` value. Pass when the destination project's DSN differs from the source's, so the destination row carries its own DSN instead of inheriting the source's.
     * @param array<string, CollectionStructure> $collectionStructures Per-database-type metadata collection structures (e.g. `['vectorsdb' => ...]`), used instead of $collectionStructure when the imported database's type has an entry. Types with an entry also get type-specific metadata written (e.g. vectorsdb collection `dimension`).
     * @param int $provisioningLease Seconds an existing `provisioning` or `failed` database that names no owner may go without an update before another migration may recover it. Such a row carries no owner for $getRecoverableOwner to judge, so its update timestamp stands in: one fresher than the lease may still belong to a migration that is provisioning it, and fails closed. Defaults to 24 hours, the longest lease a live Appwrite attempt holds. `0` recovers it at once; a negative value is rejected.
     */
    public function __construct(
        string $project,
        string $endpoint,
        string $key,
        protected UtopiaDatabase $dbForProject,
        callable $getDatabasesDB,
        protected array $collectionStructure,
        protected UtopiaDatabase $dbForPlatform,
        protected string $projectInternalId,
        ProvisioningOwner $owner,
        callable $getRecoverableOwner,
        protected OnDuplicate $onDuplicate = OnDuplicate::Fail,
        ?callable $getDatabaseDSN = null,
        protected array $collectionStructures = [],
        protected int $provisioningLease = self::DEFAULT_PROVISIONING_LEASE,
    ) {
        if ($provisioningLease < 0) {
            throw new \InvalidArgumentException('Provisioning lease must not be negative');
        }

        $this->projectId = $project;
        $this->endpoint = $endpoint;
        $this->key = $key;

        $this->client = (new Client())
            ->setEndpoint($endpoint)
            ->setProject($project)
            ->setKey($key)
            ->setConnectTimeout(static::CONNECT_TIMEOUT)
            ->setTimeout(static::REQUEST_TIMEOUT);

        $this->functions = new Functions($this->client);
        $this->messaging = new Messaging($this->client);
        $this->project = new Project($this->client);
        $this->proxy = new Proxy($this->client);
        $this->sites = new Sites($this->client);
        $this->storage = new Storage($this->client);
        $this->teams = new Teams($this->client);
        $this->users = new Users($this->client);

        $this->getDatabasesDB = $getDatabasesDB;
        $this->getDatabaseDSN = $getDatabaseDSN;
        $this->owner = $owner;
        $this->getRecoverableOwner = $getRecoverableOwner;
    }

    /**
     * Resolve the DSN written into the destination's `_databases.database`.
     * Without a resolver, leave it blank — the source DSN must never be
     * propagated as the default, since when source and destination DSNs
     * differ propagation routes destination reads to the wrong host (the
     * regression PR #151 introduced).
     */
    private function resolveDestinationDsn(Database $resource): string
    {
        if ($this->getDatabaseDSN === null) {
            return '';
        }
        return ($this->getDatabaseDSN)($resource);
    }

    private function getSupportForDatabaseStatus(): bool
    {
        // $source is a non-nullable typed property with no default; it is only set when the
        // destination runs through Transfer. Guard so direct createDatabase() calls (e.g. tests)
        // don't hit "must not be accessed before initialization".
        if (! isset($this->source) || ! $this->getSource()->supportsDatabaseStatus()) {
            return false;
        }

        return $this->databaseStatusSupported ??= $this->declaresDatabaseAttributes('status');
    }

    private function getSupportForProvisioningOwner(): bool
    {
        if (! $this->getSupportForDatabaseStatus()) {
            return false;
        }

        return $this->provisioningOwnerSupported ??= $this->declaresDatabaseAttributes(
            self::OWNER_MIGRATION_ID,
            self::OWNER_ATTEMPT_ID,
        );
    }

    private function declaresDatabaseAttributes(string ...$keys): bool
    {
        $declared = [];
        foreach ($this->dbForProject->findCollection(self::META_DATABASES)?->attributes() ?? [] as $attribute) {
            $declared[] = $attribute->key;
        }

        return \array_diff($keys, $declared) === [];
    }

    /**
     * Transfer resources without committing terminal destination state. The
     * caller must first persist its own finalization claim, then invoke
     * success() while that generation is still authoritative.
     */
    #[Override]
    public function run(
        array $resources,
        callable $callback,
        string $rootResourceId = '',
        string $rootResourceType = '',
    ): void {
        $this->reportSkippedFinalization();
        $this->resetRunState();
        parent::run($resources, $callback, $rootResourceId, $rootResourceType);
        $this->finalizationPending = $this->provisioningDatabases !== [] || $this->orphansByTable !== [];
    }

    /**
     * Finalize destination state only after the caller has fenced terminal ownership.
     * Only a run that returned is finalized: an interrupted one keeps its databases in
     * `provisioning` for a later attempt to recover.
     *
     * @throws Finalization
     */
    #[Override]
    public function success(): void
    {
        if (! $this->finalizationPending) {
            return;
        }
        $this->finalizationPending = false;

        // Flip status before the orphan sweep so a cleanup failure can't strand databases in `provisioning`.
        $failures = $this->markProvisionedDatabasesReady();
        \array_push($failures, ...$this->cleanupOverwriteOrphans());

        if ($failures !== []) {
            throw new Finalization($failures);
        }
    }

    #[Override]
    public function error(): void
    {
        $this->finalizationPending = false;
        parent::error();
    }

    /**
     * An aborted run is not finalized, whatever the source did with the abort: its databases stay
     * in `provisioning` for a later attempt, as after an interrupted run.
     */
    #[Override]
    public function markAborted(): void
    {
        $this->finalizationPending = false;
    }

    #[Override]
    public function cleanUp(): void
    {
        $this->reportSkippedFinalization();
        parent::cleanUp();
    }

    /** Per-run state must not leak across run() invocations on a reused instance. */
    private function resetRunState(): void
    {
        $this->rowBuffer = [];
        $this->orphansByTable = [];
        $this->processedTwoWayPairs = [];
        $this->provisioningDatabases = [];
        $this->finalizationPending = false;
    }

    /** A returned run that is neither finalized nor abandoned would otherwise leave its databases unusable without a trace. */
    private function reportSkippedFinalization(): void
    {
        if (! $this->finalizationPending) {
            return;
        }
        $this->finalizationPending = false;

        $databaseIds = \array_map(\strval(...), \array_keys($this->provisioningDatabases));
        foreach ($this->orphansByTable as $tracked) {
            $databaseIds[] = $tracked['database']->getId();
        }

        foreach (\array_unique($databaseIds) as $databaseId) {
            $this->addError(new Exception(
                resourceName: Resource::TYPE_DATABASE,
                resourceGroup: Transfer::GROUP_DATABASES,
                resourceId: $databaseId,
                message: 'Database was not finalized: success() was not called after the transfer',
                code: Exception::CODE_INTERNAL,
            ));
        }
    }

    /**
     * @return list<Exception>
     */
    private function markProvisionedDatabasesReady(): array
    {
        $failures = [];
        foreach (\array_keys($this->provisioningDatabases) as $databaseId) {
            try {
                if (! $this->setDatabaseStatus($databaseId, self::DATABASE_STATUS_READY)) {
                    throw new DatabaseException('Database provisioning owner changed before finalization');
                }
            } catch (\Throwable $error) {
                $failures[] = $this->recordFinalizationFailure(Resource::TYPE_DATABASE, (string) $databaseId, $error);
            }
        }

        return $failures;
    }

    private function recordFinalizationFailure(string $resourceName, string $resourceId, \Throwable $error): Exception
    {
        $failure = new Exception(
            resourceName: $resourceName,
            resourceGroup: Transfer::GROUP_DATABASES,
            resourceId: $resourceId,
            message: $error->getMessage(),
            code: $error->getCode(),
            previous: $error,
        );
        $this->addError($failure);

        return $failure;
    }

    private function setDatabaseStatus(string $databaseId, string $status): bool
    {
        if (! $this->getSupportForDatabaseStatus()) {
            return true;
        }

        try {
            $database = $this->dbForProject->getDocument(
                self::META_DATABASES,
                $databaseId,
                forUpdate: true,
            );
            if (
                $database->isEmpty()
                || $database->getAttribute('status') !== self::DATABASE_STATUS_PROVISIONING
                || ($this->getSupportForProvisioningOwner() && ! $this->carriesOwner($database, $this->owner))
            ) {
                return false;
            }

            $updated = $this->updateOwned($database, new UtopiaDocument(['status' => $status]));

            return ! $updated->isEmpty();
        } catch (ConflictException) {
            return false;
        }
    }

    /**
     * A write pinned to the timestamp the row was read with is refused once another writer got
     * there first, which holds only while every write moves `$updatedAt` strictly forward.
     * Writes here never supply `$updatedAt`, and the stored value is checked after each one.
     */
    private function updateOwned(UtopiaDocument $observed, UtopiaDocument $updates): UtopiaDocument
    {
        $readAt = $observed->getUpdatedAt();
        if ($readAt === null || $readAt === '') {
            throw new DatabaseException('Database provisioning ownership requires an update timestamp');
        }

        $updated = $this->dbForProject->withRequestTimestamp(
            new \DateTime($readAt),
            fn (): UtopiaDocument => $this->dbForProject->updateDocument(self::META_DATABASES, $observed->getId(), $updates),
        );
        if ($updated->isEmpty()) {
            return $updated;
        }

        $stored = $this->dbForProject->getDocument(self::META_DATABASES, $observed->getId(), forUpdate: true);
        if ($stored->isEmpty()) {
            return $stored;
        }

        if (new \DateTime((string) $stored->getUpdatedAt()) <= new \DateTime($readAt)) {
            throw new DatabaseException('Database '.$observed->getId().' provisioning write did not move its update timestamp forward');
        }

        return $stored;
    }

    private function getProvisioningOwner(UtopiaDocument $database): ?ProvisioningOwner
    {
        $migrationId = $database->getAttribute(self::OWNER_MIGRATION_ID);
        $attemptId = $database->getAttribute(self::OWNER_ATTEMPT_ID);
        if (
            ! \is_string($migrationId)
            || $migrationId === ''
            || ! \is_string($attemptId)
            || $attemptId === ''
        ) {
            return null;
        }

        return new ProvisioningOwner($migrationId, $attemptId);
    }

    /**
     * A row written before its schema could record an owner names none at all. A row naming
     * only part of an owner is not unowned: it fails closed like any other unattested owner.
     */
    private function isUnowned(UtopiaDocument $database): bool
    {
        if (! $this->getSupportForProvisioningOwner()) {
            return true;
        }

        foreach ([self::OWNER_MIGRATION_ID, self::OWNER_ATTEMPT_ID] as $key) {
            if (! \in_array($database->getAttribute($key), [null, ''], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws \Exception
     */
    private function isStale(UtopiaDocument $database): bool
    {
        if ($this->provisioningLease === 0) {
            return true;
        }

        $updatedAt = $database->getUpdatedAt();
        if ($updatedAt === null || $updatedAt === '') {
            return false;
        }

        return (new \DateTimeImmutable($updatedAt))->getTimestamp() + $this->provisioningLease
            < (new \DateTimeImmutable())->getTimestamp();
    }

    /** Whether the row names exactly $owner, or no owner at all when $owner is null. */
    private function carriesOwner(UtopiaDocument $database, ?ProvisioningOwner $owner): bool
    {
        if ($owner === null) {
            return $this->isUnowned($database);
        }

        $current = $this->getProvisioningOwner($database);

        return $current !== null && $current->equals($owner);
    }

    /** @return array<string, string> */
    private function provisioningOwnerAttributes(): array
    {
        if (! $this->getSupportForProvisioningOwner()) {
            return [];
        }

        return [
            self::OWNER_MIGRATION_ID => $this->owner->migrationId,
            self::OWNER_ATTEMPT_ID => $this->owner->attemptId,
        ];
    }

    /** Best-effort transition to `failed`; a secondary error here must not mask the caller's original throw. */
    private function markDatabaseFailed(string $databaseId): void
    {
        try {
            $this->setDatabaseStatus($databaseId, self::DATABASE_STATUS_FAILED);
        } catch (\Throwable) {
        }
    }

    public static function getName(): string
    {
        return 'Appwrite';
    }

    /**
     * @return array<string>
     */
    public static function getSupportedResources(): array
    {
        return [
            // Auth
            Resource::TYPE_USER,
            Resource::TYPE_TEAM,
            Resource::TYPE_MEMBERSHIP,
            Resource::TYPE_AUTH_METHODS,
            Resource::TYPE_POLICIES,
            Resource::TYPE_OAUTH2_PROVIDER,

            // Database
            Resource::TYPE_DATABASE,
            Resource::TYPE_DATABASE_DOCUMENTSDB,
            Resource::TYPE_DATABASE_VECTORSDB,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_INDEX,
            Resource::TYPE_ROW,

            // legacy
            Resource::TYPE_DOCUMENT,
            Resource::TYPE_ATTRIBUTE,
            Resource::TYPE_COLLECTION,

            // Storage
            Resource::TYPE_BUCKET,
            Resource::TYPE_FILE,

            // Functions
            Resource::TYPE_FUNCTION,
            Resource::TYPE_DEPLOYMENT,
            Resource::TYPE_ENVIRONMENT_VARIABLE,

            // Messaging
            Resource::TYPE_PROVIDER,
            Resource::TYPE_TOPIC,
            Resource::TYPE_SUBSCRIBER,
            Resource::TYPE_MESSAGE,

            // Sites
            Resource::TYPE_SITE,
            Resource::TYPE_SITE_DEPLOYMENT,
            Resource::TYPE_SITE_VARIABLE,

            // Integrations
            Resource::TYPE_PLATFORM,
            Resource::TYPE_API_KEY,
            Resource::TYPE_WEBHOOK,
            Resource::TYPE_SMTP,

            // Project
            Resource::TYPE_PROJECT_VARIABLE,
            Resource::TYPE_PROJECT_PROTOCOLS,
            Resource::TYPE_PROJECT_LABELS,
            Resource::TYPE_PROJECT_SERVICES,
            Resource::TYPE_PROJECT_EMAIL_TEMPLATE,

            // Backups
            Resource::TYPE_BACKUP_POLICY,

            // Domains
            Resource::TYPE_RULE,
        ];
    }

    /**
     * @param array<string> $resources
     * @return array<string, int>
     * @throws AppwriteException
     */
    #[Override]
    public function report(array $resources = [], array $resourceIds = []): array
    {
        if (empty($resources)) {
            $resources = $this->getSupportedResources();
        }

        $scope = '';

        // Most of these API calls are purposely wrong. Appwrite will throw a 403 before a 400.
        // We want to make sure the API key has full read and write access to the project.
        try {
            // Auth
            if (\in_array(Resource::TYPE_USER, $resources)) {
                $scope = 'users.read';
                $this->users->list();

                $scope = 'users.write';
                $this->users->create('');
            }

            if (\in_array(Resource::TYPE_TEAM, $resources)) {
                $scope = 'teams.read';
                $this->teams->list();

                $scope = 'teams.write';
                $this->teams->create('', '');
            }

            if (\in_array(Resource::TYPE_MEMBERSHIP, $resources)) {
                $scope = 'memberships.read';
                $this->teams->listMemberships('');

                $scope = 'memberships.write';
                $this->teams->createMembership('', [], '');
            }

            // Storage
            if (\in_array(Resource::TYPE_BUCKET, $resources)) {
                $scope = 'storage.read';
                $this->storage->listBuckets();

                $scope = 'storage.write';
                $this->storage->createBucket('', '');
            }

            if (\in_array(Resource::TYPE_FILE, $resources)) {
                $scope = 'files.read';
                $this->storage->listFiles('');

                $scope = 'files.write';
                $this->storage->createFile('', '', new InputFile());
            }

            // Functions
            if (\in_array(Resource::TYPE_FUNCTION, $resources)) {
                $scope = 'functions.read';
                $this->functions->list();

                $scope = 'functions.write';
                $this->functions->create('', '', Runtime::NODE180());
            }

            // Messaging
            if (\in_array(Resource::TYPE_PROVIDER, $resources)) {
                $scope = 'providers.read';
                $this->messaging->listProviders();

                $scope = 'providers.write';
                $this->messaging->createSendgridProvider('', '');
            }

            if (\in_array(Resource::TYPE_TOPIC, $resources)) {
                $scope = 'topics.read';
                $this->messaging->listTopics();

                $scope = 'topics.write';
                $this->messaging->createTopic('', '');
            }

            if (\in_array(Resource::TYPE_SUBSCRIBER, $resources)) {
                $scope = 'subscribers.read';
                $this->messaging->listSubscribers('');

                $scope = 'subscribers.write';
                $this->messaging->createSubscriber('', '', '');
            }

            if (\in_array(Resource::TYPE_MESSAGE, $resources)) {
                $scope = 'messages.read';
                $this->messaging->listMessages();

                $scope = 'messages.write';
                $this->messaging->createEmail('', '', '', draft: true);
            }

            // Sites
            if (\in_array(Resource::TYPE_SITE, $resources)) {
                $scope = 'sites.read';
                $this->sites->list();

                $scope = 'sites.write';
                $this->sites->create('', '', Framework::OTHER(), BuildRuntime::STATIC1());
            }
        } catch (AppwriteException $e) {
            if ($e->getCode() === 403) {
                throw new \Exception(
                    'Missing scope: ' . $scope,
                    (int) $e->getCode() ?: Exception::CODE_FORBIDDEN,
                    $e
                );
            }
            throw $e;
        }

        return [];
    }

    /**
     * @param array<Resource> $resources
     * @param callable(array<Resource>): void $callback
     * @return void
     */
    #[Override]
    protected function import(array $resources, callable $callback): void
    {
        if (empty($resources)) {
            return;
        }

        $total = \count($resources);

        foreach ($resources as $index => $resource) {
            $resource->setStatus(Resource::STATUS_PROCESSING);

            $isLast = $index === $total - 1;

            try {
                $this->dbForProject->setPreserveDates(true);
                $this->dbForPlatform->setPreserveDates(true);

                $responseResource = match ($resource->getGroup()) {
                    Transfer::GROUP_DATABASES => $this->importDatabaseResource($resource, $isLast),
                    Transfer::GROUP_STORAGE => $this->importFileResource($resource),
                    Transfer::GROUP_AUTH => $this->importAuthResource($resource),
                    Transfer::GROUP_FUNCTIONS => $this->importFunctionResource($resource),
                    Transfer::GROUP_MESSAGING => $this->importMessagingResource($resource),
                    Transfer::GROUP_SITES => $this->importSiteResource($resource),
                    Transfer::GROUP_INTEGRATIONS => $this->importIntegrationsResource($resource),
                    Transfer::GROUP_BACKUPS => $this->importBackupResource($resource),
                    Transfer::GROUP_PROJECTS => $this->importProjectsResource($resource),
                    Transfer::GROUP_DOMAINS => $this->importDomainsResource($resource),
                    default => throw new \Exception('Invalid resource group', Exception::CODE_VALIDATION),
                };
            } catch (\Throwable $e) {
                $resource->setStatus(Resource::STATUS_ERROR, $e->getMessage());

                $this->addError(new Exception(
                    resourceName: $resource->getName(),
                    resourceGroup: $resource->getGroup(),
                    resourceId: $resource->getId(),
                    message: $e->getMessage(),
                    code: $e->getCode(),
                    previous: $e
                ));

                $responseResource = $resource;
            } finally {
                $this->dbForProject->setPreserveDates(false);
                $this->dbForPlatform->setPreserveDates(false);
            }

            $this->cache->update($responseResource);
        }

        $callback($resources);
    }

    /**
     * @throws AppwriteException
     * @throws \Exception
     * @throws \Throwable
     */
    public function importDatabaseResource(Resource $resource, bool $isLast): Resource
    {
        $success = false;

        switch ($resource->getName()) {
            case Resource::TYPE_DATABASE:
            case Resource::TYPE_DATABASE_DOCUMENTSDB:
            case Resource::TYPE_DATABASE_VECTORSDB:
                try {
                    $this->dbForProject->setPreserveDates(false);
                    /** @var Database $resource */
                    $success = $this->createDatabase($resource);
                    break;
                } finally {
                    $this->dbForProject->setPreserveDates(true);
                }

            case Resource::TYPE_TABLE:
            case Resource::TYPE_COLLECTION:
                /** @var Table $resource */
                $success = $this->createEntity($resource);
                break;
            case Resource::TYPE_COLUMN:
            case Resource::TYPE_ATTRIBUTE:
                /** @var Column $resource */
                $success = $this->createField($resource);
                break;
            case Resource::TYPE_INDEX:
                /** @var Index $resource */
                $success = $this->createIndex($resource);
                break;
            case Resource::TYPE_ROW:
            case Resource::TYPE_DOCUMENT:
                /** @var Row $resource */
                $success = $this->createRecord($resource, $isLast);
                break;
            default:
                $success = false;
                break;
        }

        if ($success) {
            $resource->setStatus(Resource::STATUS_SUCCESS);
        }

        return $resource;
    }

    /**
     * @throws AuthorizationException
     * @throws StructureException
     * @throws DatabaseException|Exception
     */
    protected function createDatabase(Database $resource): bool
    {
        if ($resource->getId() == 'unique()') {
            $resource->setId(Id::unique());
        }

        $validator = new UID();

        if (!$validator->isValid($resource->getId())) {
            $resource->setStatus(Resource::STATUS_ERROR, $validator->getDescription());
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: $validator->getDescription(),
            ));
            return false;
        }

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);

        $existing = $this->dbForProject->getDocument(self::META_DATABASES, $resource->getId());
        $supportsStatus = $this->getSupportForDatabaseStatus();
        $status = $supportsStatus && ! $existing->isEmpty()
            ? $existing->getAttribute('status')
            : null;
        $isIncomplete = \in_array(
            $status,
            [self::DATABASE_STATUS_PROVISIONING, self::DATABASE_STATUS_FAILED],
            true,
        );
        $isUnowned = $isIncomplete && $this->isUnowned($existing);
        $expectedOwner = null;
        if ($isIncomplete && ! $isUnowned) {
            $snapshotOwner = $this->getProvisioningOwner($existing);
            $expectedOwner = ($this->getRecoverableOwner)($existing);
            if (
                $snapshotOwner === null
                || ! $expectedOwner instanceof ProvisioningOwner
                || ! $snapshotOwner->equals($expectedOwner)
                || $this->owner->attemptId === $expectedOwner->attemptId
            ) {
                throw new DatabaseException('Database '.$resource->getId().' recovery requires exact terminal-owner attestation and a fresh attempt');
            }
        } elseif ($isUnowned && ! $this->isStale($existing)) {
            throw new DatabaseException('Database '.$resource->getId().' names no owner and another migration may still be provisioning it; it becomes recoverable after '.$this->provisioningLease.' seconds without an update');
        }

        if ($this->onDuplicate !== OnDuplicate::Fail || $isIncomplete) {
            /** @var array{action: SchemaAction, database: UtopiaDocument, incomplete: bool} $claim */
            $claim = $this->dbForProject->withTransaction(function () use (
                $resource,
                $updatedAt,
                $supportsStatus,
                $status,
                $isIncomplete,
                $isUnowned,
                $expectedOwner,
            ): array {
                $locked = $this->dbForProject->getDocument(
                    self::META_DATABASES,
                    $resource->getId(),
                    forUpdate: true,
                );
                $lockedStatus = $supportsStatus && ! $locked->isEmpty()
                    ? $locked->getAttribute('status')
                    : null;
                $lockedIncomplete = \in_array(
                    $lockedStatus,
                    [self::DATABASE_STATUS_PROVISIONING, self::DATABASE_STATUS_FAILED],
                    true,
                );

                if ($isIncomplete) {
                    if ($lockedStatus !== $status || ! $this->carriesOwner($locked, $expectedOwner)) {
                        throw new DatabaseException('Database '.$resource->getId().' recovery owner changed before it could be claimed');
                    }
                    if ($isUnowned && ! $this->isStale($locked)) {
                        throw new DatabaseException('Database '.$resource->getId().' names no owner and was updated before it could be claimed');
                    }
                } elseif ($lockedIncomplete) {
                    throw new DatabaseException('Database '.$resource->getId().' requires terminal migration attestation before recovery');
                }

                $action = $this->onDuplicate->resolveSchemaAction(
                    ! $locked->isEmpty(),
                    $updatedAt,
                    $locked->getUpdatedAt(),
                );

                if ($isUnowned && $status === self::DATABASE_STATUS_PROVISIONING) {
                    // Left provisioning before it could name an owner: recovered in place as it was then, under the
                    // OnDuplicate policy (Fail skips instead of colliding), and re-enrolled for the ready flip.
                    if ($action === SchemaAction::Create || $this->databaseSpecMatches($locked, $resource)) {
                        $action = SchemaAction::Skip;
                    }
                } elseif ($isIncomplete) {
                    // A prior run created the metadata document but left the database unusable. Force an
                    // overwrite after the locked ownership check so retries can recreate its collection.
                    $action = SchemaAction::Overwrite;
                } elseif ($action !== SchemaAction::Create && $this->databaseSpecMatches($locked, $resource)) {
                    $action = SchemaAction::Skip;
                }

                $document = [];
                if ($action === SchemaAction::Overwrite) {
                    $document = [
                        'name' => $resource->getDatabaseName(),
                        'search' => implode(' ', [$resource->getId(), $resource->getDatabaseName()]),
                        'enabled' => $resource->getEnabled(),
                        'type' => empty($resource->getType()) ? 'legacy' : $resource->getType(),
                        'originalId' => empty($resource->getOriginalId()) ? null : $resource->getOriginalId(),
                        'database' => $this->resolveDestinationDsn($resource),
                    ];

                    if ($supportsStatus) {
                        $document['status'] = self::DATABASE_STATUS_PROVISIONING;
                    }
                }

                if ($action === SchemaAction::Overwrite || $isIncomplete) {
                    $document = [...$document, ...$this->provisioningOwnerAttributes()];
                }

                if ($document === [] && $isUnowned && $supportsStatus) {
                    $document = [
                        'status' => self::DATABASE_STATUS_PROVISIONING,
                        '$updatedAt' => DateTime::nowAfter($locked->getUpdatedAt()),
                    ];
                }

                if ($document !== []) {
                    $updated = $this->updateOwned($locked, new UtopiaDocument($document));
                    if ($updated->isEmpty()) {
                        throw new DatabaseException('Database '.$resource->getId().' provisioning owner changed before it could be claimed');
                    }
                }

                return [
                    'action' => $action,
                    'database' => $locked,
                    'incomplete' => $isIncomplete,
                ];
            });

            $action = $claim['action'];
            $existing = $claim['database'];
            $isIncomplete = $claim['incomplete'];

            if ($action !== SchemaAction::Create) {
                $resource->setSequence($existing->getSequence());

                // The claim transaction commits before inspecting or creating the backing collection.
                if ($isIncomplete && $this->dbForProject->findCollection($this->databaseCollectionId($existing)) === null) {
                    try {
                        $structure = $this->collectionStructureFor($resource);

                        $this->dbForProject->createCollection(UtopiaCollection::create(
                            id: $this->databaseCollectionId($existing),
                            attributes: $this->schemaAttributes($structure['attributes'] ?? []),
                            indexes: $this->schemaIndexes($structure['indexes'] ?? []),
                        ));
                    } catch (\Throwable $e) {
                        $this->markDatabaseFailed($resource->getId());
                        throw $e;
                    }
                }

                if ($supportsStatus && ($action === SchemaAction::Overwrite || $isIncomplete)) {
                    $this->provisioningDatabases[$resource->getId()] = true;
                }

                if ($action === SchemaAction::Skip) {
                    $resource->setStatus(Resource::STATUS_SKIPPED, 'Already exists on destination');
                    return false;
                }

                return true;
            }
        }

        $document = [
            '$id' => $resource->getId(),
            'name' => $resource->getDatabaseName(),
            'enabled' => $resource->getEnabled(),
            'search' => implode(' ', [$resource->getId(), $resource->getDatabaseName()]),
            '$createdAt' => $createdAt,
            '$updatedAt' => $updatedAt,
            'originalId' => empty($resource->getOriginalId()) ? null : $resource->getOriginalId(),
            'type' => empty($resource->getType()) ? 'legacy' : $resource->getType(),
            // Resolved by the destination's resolver (or left blank); never copy the source's DSN by default.
            'database' => $this->resolveDestinationDsn($resource),
        ];

        // Only SOURCE_DATABASE Appwrite migrations manage the status field: create in `provisioning`,
        // then markProvisionedDatabasesReady() flips it to `ready` once the run finishes. Every other
        // source leaves status untouched so the collection default applies. Never copy the source's state.
        if ($this->getSupportForDatabaseStatus()) {
            $document['status'] = self::DATABASE_STATUS_PROVISIONING;
            $document = [...$document, ...$this->provisioningOwnerAttributes()];
        }

        $database = $this->dbForProject->createDocument(self::META_DATABASES, new UtopiaDocument($document));

        try {
            $database = $this->dbForProject->getDocument(self::META_DATABASES, $database->getId());

            if ($database->isEmpty()) {
                throw new DatabaseException('Failed to reload created database '.$resource->getId());
            }

            $resource->setSequence($database->getSequence());
            $structure = $this->collectionStructureFor($resource);

            $this->dbForProject->createCollection(UtopiaCollection::create(
                id: $this->databaseCollectionId($database),
                attributes: $this->schemaAttributes($structure['attributes'] ?? []),
                indexes: $this->schemaIndexes($structure['indexes'] ?? []),
            ));
        } catch (\Throwable $e) {
            $this->markDatabaseFailed($resource->getId());
            throw $e;
        }

        // Fully created; when we manage status it stays provisioning until the end-of-run sweep flips it to ready.
        if ($this->getSupportForDatabaseStatus()) {
            $this->provisioningDatabases[$resource->getId()] = true;
        }

        return true;
    }

    /**
     * @throws AuthorizationException
     * @throws DatabaseException
     * @throws StructureException
     * @throws Exception
     */
    protected function createEntity(Table $resource): bool
    {
        if ($resource->getId() == 'unique()') {
            $resource->setId(Id::unique());
        }

        $validator = new UID();

        if (!$validator->isValid($resource->getId())) {
            $resource->setStatus(Resource::STATUS_ERROR, $validator->getDescription());
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: $validator->getDescription(),
            ));
            return false;
        }

        $database = $this->dbForProject->getDocument(
            self::META_DATABASES,
            $resource->getDatabase()->getId()
        );

        if ($database->isEmpty()) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Database not found');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Database not found',
            ));
            return false;
        }

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);

        $dbForDatabases = ($this->getDatabasesDB)($database);

        if (!$dbForDatabases->collectionExists(UtopiaDatabase::METADATA)) {
            $dbForDatabases->create();
        }

        if ($this->onDuplicate !== OnDuplicate::Fail) {
            $existing = $this->dbForProject->getDocument(
                $this->databaseCollectionId($database),
                $resource->getId()
            );
            $action = $this->onDuplicate->resolveSchemaAction(
                !$existing->isEmpty(),
                $updatedAt,
                $existing->getUpdatedAt(),
            );
            // Spec match → skip work. Create excluded; nothing on dest to match against.
            if ($action !== SchemaAction::Create && $this->tableSpecMatches($existing, $resource)) {
                $action = SchemaAction::Skip;
            }

            $earlyReturn = match ($action) {
                SchemaAction::Skip => (function () use ($resource, $existing): bool {
                    $resource->setSequence($existing->getSequence());
                    $resource->setStatus(Resource::STATUS_SKIPPED, 'Already exists on destination');
                    return false;
                })(),
                SchemaAction::Overwrite => (function () use ($resource, $existing, $database, $updatedAt): bool {
                    $this->dbForProject->updateDocument(
                        $this->databaseCollectionId($database),
                        $existing->getId(),
                        new UtopiaDocument([
                            'name' => $resource->getTableName(),
                            'search' => implode(' ', [$resource->getId(), $resource->getTableName()]),
                            'enabled' => $resource->getEnabled(),
                            '$permissions' => Permission::aggregate($resource->getPermissions()),
                            'documentSecurity' => $resource->getRowSecurity(),
                            '$updatedAt' => $updatedAt,
                        ] + $this->entityTypeMetadata($resource))
                    );
                    $resource->setSequence($existing->getSequence());
                    return true;
                })(),
                SchemaAction::Create => null,
            };
            if ($earlyReturn !== null) {
                return $earlyReturn;
            }
        }

        $table = $this->dbForProject->createDocument($this->databaseCollectionId($database), new UtopiaDocument([
            '$id' => $resource->getId(),
            'databaseInternalId' => $database->getSequence(),
            'databaseId' => $resource->getDatabase()->getId(),
            '$permissions' => Permission::aggregate($resource->getPermissions()),
            'documentSecurity' => $resource->getRowSecurity(),
            'enabled' => $resource->getEnabled(),
            'name' => $resource->getTableName(),
            'search' => implode(' ', [$resource->getId(), $resource->getTableName()]),
            '$createdAt' => $createdAt,
            '$updatedAt' => $updatedAt,
        ] + $this->entityTypeMetadata($resource)));

        $resource->setSequence($table->getSequence());

        $dbForDatabases->createCollection(UtopiaCollection::create(
            id: $this->tableCollectionId($database, $table),
            permissions: $resource->getPermissions(),
            documentSecurity: $resource->getRowSecurity(),
        ));

        return true;
    }

    /**
     * @throws AppwriteException
     * @throws \Exception
     * @throws \Throwable
     */
    protected function createField(Column|Attribute $resource): bool
    {
        if ($resource->getTable()->getDatabase()->getType() === Resource::TYPE_DATABASE_DOCUMENTSDB) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'Columns not supported for DocumentsDB');
            return false;
        }
        // column will be matching attribute as well
        // column type will be matching attribute type as well
        $type = $this->schemaColumnType($resource);

        $database = $this->dbForProject->getDocument(
            self::META_DATABASES,
            $resource->getTable()->getDatabase()->getId(),
        );

        if ($database->isEmpty()) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Database not found');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Database not found',
            ));
            return false;
        }

        $table = $this->dbForProject->getDocument(
            $this->databaseCollectionId($database),
            $resource->getTable()->getId(),
        );

        if ($table->isEmpty()) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Table not found');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Table not found',
            ));
            return false;
        }

        if (!empty($resource->getFormat())) {
            if (!Structure::hasFormat($resource->getFormat(), UtopiaAttribute::typeFromStored($type))) {
                $resource->setStatus(Resource::STATUS_ERROR, "Format {$resource->getFormat()} not available for column type {$type}");
                $this->addError(new Exception(
                    resourceName: $resource->getName(),
                    resourceGroup: $resource->getGroup(),
                    resourceId: $resource->getId(),
                    message: "Format {$resource->getFormat()} not available for column type {$type}",
                ));
                return false;
            }
        }

        if ($resource->isRequired() && $resource->getDefault() !== null) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Cannot set default value for required column');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Cannot set default value for required column',
            ));
            return false;
        }

        if ($resource->isArray() && $resource->getDefault() !== null) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Cannot set default value for array column');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Cannot set default value for array column',
            ));
            return false;
        }

        $relatedTable = null;
        if ($type === ColumnType::Relationship->value) {
            $resource->getOptions()['side'] = RelationshipSide::Parent->value;
            $relatedTable = $this->dbForProject->getDocument(
                $this->databaseCollectionId($database),
                $resource->getOptions()['relatedCollection']
            );
            if ($relatedTable->isEmpty()) {
                $resource->setStatus(Resource::STATUS_ERROR, 'Related table not found');
                $this->addError(new Exception(
                    resourceName: $resource->getName(),
                    resourceGroup: $resource->getGroup(),
                    resourceId: $resource->getId(),
                    message: 'Related table not found',
                ));
                return false;
            }
        }

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);
        $dbForDatabases = ($this->getDatabasesDB)($database);

        $this->trackOrphanCandidate($database, $table, 'attributeKeys', $resource->getKey(), $dbForDatabases);

        $isRelationship = $type === ColumnType::Relationship->value;

        // Source emits both sides of a two-way; processing one side reconciles both. Partner skip.
        $twoWayPairKey = $this->twoWayPairKey($database, $table, $resource, $type);
        if ($twoWayPairKey !== null && isset($this->processedTwoWayPairs[$twoWayPairKey])) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'Two-way partner already reconciled');
            return false;
        }

        $attributeMetaId = $this->attributeIndexMetaId($database, $table, $resource->getKey());
        if ($this->onDuplicate !== OnDuplicate::Fail) {
            $existingAttr = $this->dbForProject->getDocument(self::META_ATTRIBUTES, $attributeMetaId);
            $action = $this->onDuplicate->resolveSchemaAction(
                !$existingAttr->isEmpty(),
                $updatedAt,
                $existingAttr->getUpdatedAt(),
            );
            // Spec match → skip work. Create excluded; nothing on dest to match against.
            if ($action !== SchemaAction::Create && $this->attributeSpecMatches($existingAttr, $resource, $type, $isRelationship)) {
                $action = SchemaAction::Skip;
            }

            $earlyReturn = match ($action) {
                SchemaAction::Skip => (function () use ($resource, $database, $table, $dbForDatabases): bool {
                    $this->purgeTableCaches($database, $table, $dbForDatabases);
                    $resource->setStatus(Resource::STATUS_SKIPPED, 'Already exists on destination');
                    return false;
                })(),
                SchemaAction::Overwrite => ($isRelationship
                    ? $this->updateRelationshipInPlace($database, $table, $resource, $type, $updatedAt, $existingAttr, $dbForDatabases)
                    : $this->updateAttributeInPlace($database, $table, $resource, $type, $updatedAt, $existingAttr, $dbForDatabases))
                    ? true
                    : null,
                SchemaAction::Create => null,
            };
            if ($earlyReturn !== null) {
                if ($twoWayPairKey !== null) {
                    $this->processedTwoWayPairs[$twoWayPairKey] = true;
                }
                return $earlyReturn;
            }

            if ($action === SchemaAction::Overwrite) {
                $this->dropAttributeForRecreate($database, $table, $resource, $dbForDatabases, $existingAttr);
            }
        }

        try {
            $column = new UtopiaDocument([
                '$id' => Id::custom($attributeMetaId),
                'key' => $resource->getKey(),
                'databaseInternalId' => $database->getSequence(),
                'databaseId' => $database->getId(),
                'collectionInternalId' => $table->getSequence(),
                'collectionId' => $table->getId(),
                'type' => $type,
                'status' => 'available',
                'size' => $resource->getSize(),
                'required' => $resource->isRequired(),
                'signed' => $resource->isSigned(),
                'default' => $resource->getDefault(),
                'array' => $resource->isArray(),
                'format' => $resource->getFormat(),
                'formatOptions' => $resource->getFormatOptions(),
                'filters' => $resource->getFilters(),
                'options' => $resource->getOptions(),
                '$createdAt' => $createdAt,
                '$updatedAt' => $updatedAt,
            ]);

            $dbForDatabases->checkAttribute($this->tableCollectionId($database, $table), $this->resourceAttribute($resource, $type));

            $column = $this->dbForProject->createDocument(self::META_ATTRIBUTES, $column);
        } catch (DuplicateException $e) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Attribute already exists');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Attribute already exists',
                previous: $e,
            ));
            return false;
        } catch (LimitException $e) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Attribute limit exceeded');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Attribute limit exceeded',
                previous: $e,
            ));
            return false;
        } catch (\Throwable $e) {
            $this->purgeTableCaches($database, $table, $dbForDatabases);
            throw $e;
        }

        $this->purgeTableCaches($database, $table, $dbForDatabases);
        $options = $resource->getOptions();

        $twoWayKey = null;

        if ($type === ColumnType::Relationship->value && $options['twoWay'] && $relatedTable !== null) {
            $twoWayKey = $options['twoWayKey'];
            $options['relatedCollection'] = $table->getId();
            $options['twoWayKey'] = $resource->getKey();
            $options['side'] = RelationshipSide::Child->value;

            try {
                $twoWayAttribute = new UtopiaDocument([
                    '$id' => Id::custom($this->attributeIndexMetaId($database, $relatedTable, $twoWayKey)),
                    'key' => $twoWayKey,
                    'databaseInternalId' => $database->getSequence(),
                    'databaseId' => $database->getId(),
                    'collectionInternalId' => $relatedTable->getSequence(),
                    'collectionId' => $relatedTable->getId(),
                    'type' => $type,
                    'status' => 'available',
                    'size' => $resource->getSize(),
                    'required' => $resource->isRequired(),
                    'signed' => $resource->isSigned(),
                    'default' => $resource->getDefault(),
                    'array' => $resource->isArray(),
                    'format' => $resource->getFormat(),
                    'formatOptions' => $resource->getFormatOptions(),
                    'filters' => $resource->getFilters(),
                    'options' => $options,
                    '$createdAt' => $createdAt,
                    '$updatedAt' => $updatedAt,
                ]);

                $this->dbForProject->createDocument(self::META_ATTRIBUTES, $twoWayAttribute);
                $this->trackOrphanCandidate($database, $relatedTable, 'attributeKeys', $twoWayKey, $dbForDatabases);
            } catch (DuplicateException $e) {
                $this->dbForProject->deleteDocument(self::META_ATTRIBUTES, $column->getId());

                $resource->setStatus(Resource::STATUS_ERROR, 'Attribute already exists');
                $this->addError(new Exception(
                    resourceName: $resource->getName(),
                    resourceGroup: $resource->getGroup(),
                    resourceId: $resource->getId(),
                    message: 'Attribute already exists',
                    previous: $e,
                ));
                return false;
            } catch (LimitException $e) {
                $this->dbForProject->deleteDocument(self::META_ATTRIBUTES, $column->getId());

                $resource->setStatus(Resource::STATUS_ERROR, 'Attribute limit exceeded');
                $this->addError(new Exception(
                    resourceName: $resource->getName(),
                    resourceGroup: $resource->getGroup(),
                    resourceId: $resource->getId(),
                    message: 'Attribute limit exceeded',
                    previous: $e,
                ));
                return false;
            } catch (\Throwable $e) {
                $this->purgeTableCaches($database, $relatedTable, $dbForDatabases);
                throw $e;
            }
        }

        try {
            switch ($type) {
                case ColumnType::Relationship->value:
                    if ($relatedTable === null) {
                        throw new Exception(
                            resourceName: $resource->getName(),
                            resourceGroup: $resource->getGroup(),
                            resourceId: $resource->getId(),
                            message: 'Related table not found',
                        );
                    }
                    $dbForDatabases->createRelationship($this->tableCollectionId($database, $table), UtopiaRelationship::fromArray([
                        'relatedCollection' => $this->tableCollectionId($database, $relatedTable),
                        'relationType' => $options['relationType'],
                        'twoWay' => $options['twoWay'],
                        'key' => $resource->getKey(),
                        'twoWayKey' => (string) ($options['twoWay'] ? $twoWayKey : $options['twoWayKey'] ?? ''),
                        'onDelete' => $options['onDelete'],
                    ]));
                    break;
                default:
                    $dbForDatabases->createAttribute(
                        $this->tableCollectionId($database, $table),
                        UtopiaAttribute::fromArray([
                            'key' => $resource->getKey(),
                            'type' => $type,
                            'size' => $resource->getSize(),
                            'required' => $resource->isRequired(),
                            'default' => $resource->getDefault(),
                            'signed' => $resource->isSigned(),
                            'array' => $resource->isArray(),
                            'format' => $resource->getFormat(),
                            'formatOptions' => $resource->getFormatOptions(),
                            'filters' => $resource->getFilters(),
                        ]),
                    );
            }
        } catch (\Throwable $e) {
            $this->dbForProject->deleteDocument(self::META_ATTRIBUTES, $column->getId());

            if (isset($twoWayAttribute)) {
                $this->dbForProject->deleteDocument(self::META_ATTRIBUTES, $twoWayAttribute->getId());
            }

            throw $e;
        }

        if ($type === ColumnType::Relationship->value && $options['twoWay'] && $relatedTable !== null) {
            $this->dbForProject->purgeCachedDocument($this->databaseCollectionId($database), $relatedTable->getId());
        }

        $this->purgeTableCaches($database, $table, $dbForDatabases);

        if ($twoWayPairKey !== null) {
            $this->processedTwoWayPairs[$twoWayPairKey] = true;
        }

        $this->syncVectorDimension($resource, $type, $database, $table);

        return true;
    }

    /**
     * @return CollectionStructure
     */
    private function collectionStructureFor(Database $resource): array
    {
        return $this->collectionStructures[$resource->getType()] ?? $this->collectionStructure;
    }

    private function schemaColumnType(Column|Attribute $resource): string
    {
        return match ($resource->getType()) {
            Column::TYPE_DATETIME => UtopiaAttribute::storedType(ColumnType::Datetime),
            Column::TYPE_BOOLEAN => UtopiaAttribute::storedType(ColumnType::Boolean),
            Column::TYPE_INTEGER => UtopiaAttribute::storedType(ColumnType::Integer),
            Column::TYPE_BIG_INT => UtopiaAttribute::storedType(ColumnType::BigInteger),
            Column::TYPE_FLOAT => UtopiaAttribute::storedType(ColumnType::Double),
            Column::TYPE_RELATIONSHIP => UtopiaAttribute::storedType(ColumnType::Relationship),
            Column::TYPE_STRING,
            Column::TYPE_IP,
            Column::TYPE_EMAIL,
            Column::TYPE_URL,
            Column::TYPE_ENUM => UtopiaAttribute::storedType(ColumnType::String),
            Column::TYPE_POINT => UtopiaAttribute::storedType(ColumnType::Point),
            Column::TYPE_LINE => UtopiaAttribute::storedType(ColumnType::Linestring),
            Column::TYPE_POLYGON => UtopiaAttribute::storedType(ColumnType::Polygon),
            Column::TYPE_TEXT => UtopiaAttribute::storedType(ColumnType::Text),
            Column::TYPE_VARCHAR => UtopiaAttribute::storedType(ColumnType::Varchar),
            Column::TYPE_MEDIUMTEXT => UtopiaAttribute::storedType(ColumnType::MediumText),
            Column::TYPE_LONGTEXT => UtopiaAttribute::storedType(ColumnType::LongText),
            Column::TYPE_OBJECT => UtopiaAttribute::storedType(ColumnType::Object),
            Column::TYPE_VECTOR => UtopiaAttribute::storedType(ColumnType::Vector),
            default => throw new \Exception('Invalid resource type ' . $resource->getType(), Exception::CODE_VALIDATION),
        };
    }

    /**
     * @param array<mixed> $attributes
     * @return array<UtopiaAttribute>
     */
    private function schemaAttributes(array $attributes): array
    {
        return \array_map(function (mixed $attr): UtopiaAttribute {
            if ($attr instanceof UtopiaAttribute) {
                return $attr;
            }
            if ($attr instanceof UtopiaDocument) {
                return UtopiaAttribute::fromArray($attr->getArrayCopy());
            }

            return UtopiaAttribute::fromArray(\is_array($attr) ? $attr : []);
        }, $attributes);
    }

    /**
     * @param array<mixed> $indexes
     * @return array<UtopiaIndex>
     */
    private function schemaIndexes(array $indexes): array
    {
        return \array_map(function (mixed $index): UtopiaIndex {
            if ($index instanceof UtopiaIndex) {
                return $index;
            }
            if ($index instanceof UtopiaDocument) {
                return UtopiaIndex::fromArray($index->getArrayCopy());
            }

            return UtopiaIndex::fromArray(\is_array($index) ? $index : []);
        }, $indexes);
    }

    /**
     * Type-specific metadata for an imported entity's collection document. VectorsDB collections
     * carry a required `dimension`; archives written before it was serialized have none, so a
     * placeholder satisfies the schema until {@see self::syncVectorDimension()} corrects it from
     * the vector column's size.
     *
     * @return array<string, mixed>
     */
    private function entityTypeMetadata(Table $resource): array
    {
        if (
            $resource instanceof CollectionResource
            && $resource->getDatabase()->getType() === Resource::TYPE_DATABASE_VECTORSDB
            && isset($this->collectionStructures[Resource::TYPE_DATABASE_VECTORSDB])
        ) {
            return ['dimension' => $resource->getDimension() ?? 0];
        }

        return [];
    }

    /**
     * The `embeddings` column is the schema-defining vector of a vectorsdb collection, so it alone
     * drives the collection's `dimension` — correcting both the pre-dimension-archive placeholder
     * and any stale archived value, while other vector columns never touch it.
     *
     * @throws \Throwable
     */
    private function syncVectorDimension(Column|Attribute $resource, string $type, UtopiaDocument $database, UtopiaDocument $table): void
    {
        if (
            $type !== ColumnType::Vector->value
            || $resource->getKey() !== self::VECTORSDB_EMBEDDINGS_KEY
            || $resource->getTable()->getDatabase()->getType() !== Resource::TYPE_DATABASE_VECTORSDB
            || !isset($this->collectionStructures[Resource::TYPE_DATABASE_VECTORSDB])
        ) {
            return;
        }

        if ((int) $table->getAttribute('dimension', 0) === $resource->getSize()) {
            return;
        }

        $this->dbForProject->updateDocument(
            $this->databaseCollectionId($database),
            $table->getId(),
            new UtopiaDocument(['dimension' => $resource->getSize()])
        );
    }

    /**
     * @throws Exception
     * @throws \Throwable
     */
    protected function createIndex(Index $resource): bool
    {
        $database = $this->dbForProject->getDocument(
            self::META_DATABASES,
            $resource->getTable()->getDatabase()->getId(),
        );
        if ($database->isEmpty()) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Database not found');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Database not found',
            ));
            return false;
        }

        $table = $this->dbForProject->getDocument(
            $this->databaseCollectionId($database),
            $resource->getTable()->getId(),
        );
        if ($table->isEmpty()) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Table not found');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Table not found',
            ));
            return false;
        }
        $dbForDatabases = ($this->getDatabasesDB)($database);

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);

        $this->trackOrphanCandidate($database, $table, 'indexKeys', $resource->getKey(), $dbForDatabases);

        $indexMetaId = $this->attributeIndexMetaId($database, $table, $resource->getKey());

        // Pre-check + drop runs BEFORE count/validator so the to-be-dropped index isn't included in
        // the limit count or in IndexValidator's $tableIndexes (otherwise an Overwrite recreate at the
        // ceiling throws "Index limit reached" or "Invalid index" even though the net change is zero).
        if ($this->onDuplicate !== OnDuplicate::Fail) {
            $existingIdx = $this->dbForProject->getDocument(self::META_INDEXES, $indexMetaId);
            $action = $this->onDuplicate->resolveSchemaAction(
                !$existingIdx->isEmpty(),
                $updatedAt,
                $existingIdx->getUpdatedAt(),
            );
            // Spec match → skip work. Create excluded; nothing on dest to match against.
            if ($action !== SchemaAction::Create && $this->indexSpecMatches($existingIdx, $resource)) {
                $action = SchemaAction::Skip;
            }

            // Indexes have no in-place primitive — any action other than Skip falls through to drop+recreate.
            $earlyReturn = match ($action) {
                SchemaAction::Skip => (function () use ($resource, $database, $table): bool {
                    $this->dbForProject->purgeCachedDocument($this->databaseCollectionId($database), $table->getId());
                    $resource->setStatus(Resource::STATUS_SKIPPED, 'Already exists on destination');
                    return false;
                })(),
                SchemaAction::Overwrite, SchemaAction::Create => null,
            };
            if ($earlyReturn !== null) {
                return $earlyReturn;
            }

            if ($action === SchemaAction::Overwrite) {
                $dbForDatabases->deleteIndex($this->tableCollectionId($database, $table), $resource->getKey());
                $this->dbForProject->deleteDocument(self::META_INDEXES, $indexMetaId);
                $this->dbForProject->purgeCachedDocument($this->databaseCollectionId($database), $table->getId());
                // Reload $table — in-memory copy still holds the dropped index, so IndexValidator below would over-count.
                $table = $this->dbForProject->getDocument($this->databaseCollectionId($database), $table->getId());
            }
        }

        $count = $this->dbForProject->count(self::META_INDEXES, [
            Query::equal('collectionInternalId', [$table->getSequence()]),
            Query::equal('databaseInternalId', [$database->getSequence()])
        ], $dbForDatabases->getLimitForIndexes());

        if ($count >= $dbForDatabases->getLimitForIndexes()) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Index limit reached for table');
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Index limit reached for table',
            ));
            return false;
        }

        // Lengths hidden by default
        $lengths = [];

        if ($dbForDatabases->getAdapter()->supports(Capability::DefinedAttributes)) {
            $this->validateFieldsForIndexes($resource, $table, $lengths);
        }

        $index = new UtopiaDocument([
            '$id' => Id::custom($indexMetaId),
            'key' => $resource->getKey(),
            'status' => 'available', // processing, available, failed, deleting, stuck
            'databaseInternalId' => $database->getSequence(),
            'databaseId' => $database->getId(),
            'collectionInternalId' => $table->getSequence(),
            'collectionId' => $table->getId(),
            'type' => $resource->getType(),
            'attributes' => $resource->getColumns(),
            'lengths' => $lengths,
            'orders' => $resource->getOrders(),
            '$createdAt' => $createdAt,
            '$updatedAt' => $updatedAt,
        ]);

        /**
         * @var array<UtopiaDocument> $tableColumns
         */
        $tableColumns = $table->getAttribute('attributes', []);
        $tableIndexes = $table->getAttribute('indexes', []);

        $validator = new IndexValidator(
            $tableColumns,
            $tableIndexes,
            $dbForDatabases->profile(),
        );

        if (!$validator->isValid($index)) {
            $resource->setStatus(Resource::STATUS_ERROR, 'Invalid index: ' . $validator->getDescription());
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Invalid index: ' . $validator->getDescription(),
            ));
            return false;
        }

        $index = $this->dbForProject->createDocument(self::META_INDEXES, $index);

        try {
            $dbForDatabases->createIndex(
                $this->tableCollectionId($database, $table),
                UtopiaIndex::fromArray([
                    'key' => $resource->getKey(),
                    'type' => $resource->getType(),
                    'attributes' => $resource->getColumns(),
                    'lengths' => $lengths,
                    'orders' => $resource->getOrders(),
                ]),
            );
        } catch (\Throwable $th) {
            $this->dbForProject->deleteDocument(self::META_INDEXES, $index->getId());

            throw $th;
        }

        $this->dbForProject->purgeCachedDocument($this->databaseCollectionId($database), $table->getId());

        return true;
    }

    /**
     * @throws AuthorizationException
     * @throws DatabaseException
     * @throws StructureException
     * @throws Exception
     */
    protected function createRecord(Row $resource, bool $isLast): bool
    {
        if ($resource->getId() == 'unique()') {
            $resource->setId(Id::unique());
        }

        $validator = new UID();

        if (!$validator->isValid($resource->getId())) {
            $resource->setStatus(Resource::STATUS_ERROR, $validator->getDescription());
            $this->addError(new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: $validator->getDescription(),
            ));
            return false;
        }

        // Check if document has already been created
        $exists = \array_key_exists(
            $resource->getId(),
            $this->cache->get($resource->getName())
        );

        if ($exists) {
            $resource->setStatus(
                Resource::STATUS_SKIPPED,
                'Row has already been created'
            );
            return false;
        }

        $data = $resource->getData();

        $hasCreatedAt = !empty($data['$createdAt']);
        $hasUpdatedAt = !empty($data['$updatedAt']);

        if (! $hasCreatedAt) {
            $createdAt = $resource->getCreatedAt();
            if (empty($createdAt)) {
                $createdAt = $data['created_at'] ?? $data['createdAt'] ?? null;
            }

            $data['$createdAt'] = $this->normalizeDateTime($createdAt);
        }

        if (! $hasUpdatedAt) {
            $updatedAt = $resource->getUpdatedAt();
            if (empty($updatedAt)) {
                $updatedAt = $data['updated_at'] ?? $data['updatedAt'] ?? null;
            }

            if (empty($updatedAt)) {
                $data['$updatedAt'] = (string) $data['$createdAt'];
            } else {
                $data['$updatedAt'] = $this->normalizeDateTime($updatedAt, $data['$createdAt']);
            }
        }

        $this->rowBuffer[] = new UtopiaDocument(\array_merge([
            '$id' => $resource->getId(),
            '$permissions' => $resource->getPermissions(),
        ], $data));

        if ($isLast) {
            try {
                $database = $this->dbForProject->getDocument(
                    self::META_DATABASES,
                    $resource->getTable()->getDatabase()->getId(),
                );

                $table = $this->dbForProject->getDocument(
                    $this->databaseCollectionId($database),
                    $resource->getTable()->getId(),
                );

                $dbForDatabases = ($this->getDatabasesDB)($database);

                // Drop schema orphans before rows land so the Structure validator doesn't reject on orphan required columns.
                $this->cleanupOverwriteOrphansForTable($this->tableIdentity($database, $table));
                // Reload $table — in-memory copy still holds the dropped attributes, so the strip loop below would over-keep.
                $table = $this->dbForProject->getDocument(
                    $this->databaseCollectionId($database),
                    $resource->getTable()->getId(),
                );
                // Strip row payload fields the table doesn't declare — guards against orphans surviving in source archives.
                if ($dbForDatabases->getAdapter()->supports(Capability::DefinedAttributes)) {
                    $declaredKeys = [];
                    foreach ($table->getAttribute('attributes', []) as $attribute) {
                        $declaredKey = $attribute instanceof UtopiaAttribute
                            ? $attribute->key
                            : (string) $attribute->getAttribute('key', '');
                        $declaredKeys[$declaredKey] = true;
                    }

                    foreach ($this->rowBuffer as $row) {
                        foreach (\array_keys($row->getAttributes()) as $key) {
                            if (\str_starts_with((string) $key, '$') || isset($declaredKeys[$key])) {
                                continue;
                            }

                            $row->removeAttribute((string) $key);
                        }
                    }
                }
                $collectionId = $this->tableCollectionId($database, $table);

                try {
                    match ($this->onDuplicate) {
                        OnDuplicate::Overwrite => $dbForDatabases->skipRelationshipsExistCheck(
                            fn () => $dbForDatabases->upsertDocuments($collectionId, $this->rowBuffer)
                        ),
                        OnDuplicate::Skip => $dbForDatabases->ignoreDuplicates(
                            fn () => $dbForDatabases->skipRelationshipsExistCheck(
                                fn () => $dbForDatabases->createDocuments($collectionId, $this->rowBuffer)
                            )
                        ),
                        OnDuplicate::Fail => $dbForDatabases->skipRelationshipsExistCheck(
                            fn () => $dbForDatabases->createDocuments($collectionId, $this->rowBuffer)
                        ),
                    };
                } catch (DuplicateException $e) {
                    $resource->setStatus(Resource::STATUS_ERROR, 'Document already exists');
                    $this->addError(new Exception(
                        resourceName: $resource->getName(),
                        resourceGroup: $resource->getGroup(),
                        resourceId: $resource->getId(),
                        message: 'Document already exists',
                        previous: $e,
                    ));
                    return false;
                } catch (StructureException $e) {
                    $resource->setStatus(Resource::STATUS_ERROR, $e->getMessage());
                    $this->addError(new Exception(
                        resourceName: $resource->getName(),
                        resourceGroup: $resource->getGroup(),
                        resourceId: $resource->getId(),
                        message: $e->getMessage(),
                        previous: $e,
                    ));
                    return false;
                }
            } finally {
                $this->rowBuffer = [];
            }
        }


        return true;
    }

    /** Relationships route through deleteRelationship since deleteAttribute throws for relationship columns. */
    private function dropAttributeForRecreate(
        UtopiaDocument $database,
        UtopiaDocument $table,
        Column|Attribute $resource,
        UtopiaDatabase $dbForDatabases,
        UtopiaDocument $existingAttr,
    ): void {
        $collectionId = $this->tableCollectionId($database, $table);
        $attributeMetaId = $this->attributeIndexMetaId($database, $table, $resource->getKey());
        $isRelationship = $resource->getType() === Column::TYPE_RELATIONSHIP;

        if ($isRelationship) {
            $dbForDatabases->deleteRelationship($collectionId, $resource->getKey());
        } else {
            $dbForDatabases->deleteAttribute($collectionId, $resource->getKey());
        }

        $this->dbForProject->deleteDocument(self::META_ATTRIBUTES, $attributeMetaId);

        // Use dest's options for partner lookup — drop fires when relatedCollection/twoWayKey differ, so source points to the NEW partner.
        if ($isRelationship) {
            $partner = $this->resolveTwoWayPartner($database, (array) $existingAttr->getAttribute('options', []));
            if ($partner !== null) {
                $this->bestEffort(fn () => $this->dbForProject->deleteDocument(self::META_ATTRIBUTES, $partner['partnerMetaId']));
            }
        }

        $this->purgeTableCaches($database, $table, $dbForDatabases);
    }

    /** Returns false when the source change isn't SDK-expressible — caller falls through to drop+recreate. */
    private function updateAttributeInPlace(
        UtopiaDocument $database,
        UtopiaDocument $table,
        Column|Attribute $resource,
        string $type,
        string $updatedAt,
        UtopiaDocument $existingAttr,
        UtopiaDatabase $dbForDatabases,
    ): bool {
        if (!$this->typeMatches($existingAttr->getAttribute('type'), $type)) {
            return false;
        }

        $attributes = $this->normalisedAttributes($existingAttr, $resource, $type);
        if ($attributes === null) {
            return false;
        }
        [$existing, $wanted] = $attributes;

        if (!$this->immutableFieldsMatch($existingAttr, $existing, $resource, $wanted)) {
            return false;
        }

        // Pass existing values for non-SDK fields so utopia doesn't trigger an ALTER for unchanged fields.
        $dbForDatabases->updateAttribute(
            collection: $this->tableCollectionId($database, $table),
            key: $resource->getKey(),
            update: new AttributeUpdate(
                type: UtopiaAttribute::typeFromStored($type),
                size: $resource->getSize(),
                required: $resource->isRequired(),
                default: $resource->getDefault(),
                signed: $existing->signed,
                array: $existing->array,
                format: $resource->getFormat() !== '' ? new Format($resource->getFormat(), $resource->getFormatOptions()) : null,
                filters: $existing->filters,
            ),
        );

        $this->dbForProject->updateDocument(self::META_ATTRIBUTES, $existingAttr->getId(), new UtopiaDocument([
            'key' => $resource->getKey(),
            'type' => $type,
            'size' => $resource->getSize(),
            'required' => $resource->isRequired(),
            'signed' => $resource->isSigned(),
            'default' => $resource->getDefault(),
            'array' => $resource->isArray(),
            'format' => $resource->getFormat(),
            'formatOptions' => $resource->getFormatOptions(),
            'filters' => $resource->getFilters(),
            '$updatedAt' => $updatedAt,
        ]));

        $this->purgeTableCaches($database, $table, $dbForDatabases);
        return true;
    }

    /**
     * Returns false when the source change isn't SDK-expressible — caller falls through to drop+recreate.
     * One-way + onDelete change is also rejected: utopia's updateRelationship partner-cascade throws on one-way.
     */
    private function updateRelationshipInPlace(
        UtopiaDocument $database,
        UtopiaDocument $table,
        Column|Attribute $resource,
        string $type,
        string $updatedAt,
        UtopiaDocument $existingAttr,
        UtopiaDatabase $dbForDatabases,
    ): bool {
        $sourceOptions = $resource->getOptions();
        $destOptions = $existingAttr->getAttribute('options', []);

        if ($this->arraysDifferOnKeys($sourceOptions, $destOptions, self::RELATIONSHIP_IMMUTABLE_FIELDS)) {
            return false;
        }

        $isTwoWay = (bool) ($destOptions['twoWay'] ?? false);
        $onDeleteChanged = ($sourceOptions['onDelete'] ?? null) !== ($destOptions['onDelete'] ?? null);
        $onDelete = RelationshipDeleteAction::tryFrom((string) ($sourceOptions['onDelete'] ?? ''));

        if (!$isTwoWay && $onDeleteChanged) {
            return false;
        }

        if ($onDeleteChanged) {
            $dbForDatabases->updateRelationship(
                collection: $this->tableCollectionId($database, $table),
                key: $resource->getKey(),
                update: new RelationshipUpdate(onDelete: $onDelete),
            );
        }

        $this->dbForProject->updateDocument(self::META_ATTRIBUTES, $existingAttr->getId(), new UtopiaDocument([
            'key' => $resource->getKey(),
            'type' => $type,
            'options' => array_merge($destOptions, [
                'onDelete' => $onDelete?->value ?? $destOptions['onDelete'] ?? null,
            ]),
            '$updatedAt' => $updatedAt,
        ]));

        $this->purgeTableCaches($database, $table, $dbForDatabases);

        // utopia syncs both physical sides; partner's Appwrite-level meta doc has to be refreshed by hand.
        if ($isTwoWay) {
            $this->refreshTwoWayPartnerOnDelete($database, $destOptions, $onDelete, $updatedAt, $dbForDatabases);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $destOptions
     */
    private function refreshTwoWayPartnerOnDelete(
        UtopiaDocument $database,
        array $destOptions,
        ?RelationshipDeleteAction $onDelete,
        string $updatedAt,
        UtopiaDatabase $dbForDatabases,
    ): void {
        $partner = $this->resolveTwoWayPartner($database, $destOptions);
        if ($partner === null) {
            return;
        }

        $partnerMeta = $this->dbForProject->getDocument(self::META_ATTRIBUTES, $partner['partnerMetaId']);
        if ($partnerMeta->isEmpty()) {
            return;
        }

        $partnerOptions = $partnerMeta->getAttribute('options', []);
        $this->dbForProject->updateDocument(self::META_ATTRIBUTES, $partnerMeta->getId(), new UtopiaDocument([
            'options' => array_merge($partnerOptions, [
                'onDelete' => $onDelete?->value ?? $partnerOptions['onDelete'] ?? null,
            ]),
            '$updatedAt' => $updatedAt,
        ]));
        $this->purgeTableCaches($database, $partner['relatedTable'], $dbForDatabases);
    }

    private function databaseSpecMatches(UtopiaDocument $existing, Database $resource): bool
    {
        $sourceType = empty($resource->getType()) ? 'legacy' : $resource->getType();
        $sourceOriginalId = empty($resource->getOriginalId()) ? null : $resource->getOriginalId();

        return $existing->getAttribute('name')       === $resource->getDatabaseName()
            && $existing->getAttribute('enabled')    === $resource->getEnabled()
            && $existing->getAttribute('type')       === $sourceType
            && $existing->getAttribute('originalId') === $sourceOriginalId
            && $existing->getAttribute('database')   === $this->resolveDestinationDsn($resource);
    }

    private function tableSpecMatches(UtopiaDocument $existing, Table $resource): bool
    {
        if ($existing->getAttribute('name') !== $resource->getTableName()
            || $existing->getAttribute('enabled') !== $resource->getEnabled()
            || $existing->getAttribute('documentSecurity') !== $resource->getRowSecurity()) {
            return false;
        }

        $sourcePerms = Permission::aggregate($resource->getPermissions());
        /** @var list<string> $destPerms */
        $destPerms = $existing->getAttribute('$permissions', []);
        \sort($sourcePerms);
        \sort($destPerms);
        return $sourcePerms === $destPerms;
    }

    /** Full-spec equality: short-circuits Overwrite to Skip when nothing changed. */
    private function attributeSpecMatches(UtopiaDocument $existing, Column|Attribute $resource, string $type, bool $isRelationship): bool
    {
        if (!$this->typeMatches($existing->getAttribute('type'), $type)) {
            return false;
        }
        if ($isRelationship) {
            $sourceOptions = $resource->getOptions();
            /** @var array<string, mixed> $destOptions */
            $destOptions = $existing->getAttribute('options', []);
            foreach (self::RELATIONSHIP_IMMUTABLE_FIELDS as $field) {
                if (!$this->valuesMatch($sourceOptions[$field] ?? null, $destOptions[$field] ?? null)) {
                    return false;
                }
            }
            return $this->valuesMatch($sourceOptions['onDelete'] ?? null, $destOptions['onDelete'] ?? null);
        }

        $attributes = $this->normalisedAttributes($existing, $resource, $type);
        if ($attributes === null) {
            return false;
        }
        [$deployed, $wanted] = $attributes;

        return $deployed->size === $wanted->size
            && $deployed->required === $wanted->required
            && $deployed->default === $wanted->default
            && $this->immutableFieldsMatch($existing, $deployed, $resource, $wanted);
    }

    private function immutableFieldsMatch(UtopiaDocument $existing, UtopiaAttribute $deployed, Column|Attribute $resource, UtopiaAttribute $wanted): bool
    {
        return !$this->arraysDifferOnKeys(
            $this->immutableFields($deployed, $existing->getAttribute('formatOptions', [])),
            $this->immutableFields($wanted, $resource->getFormatOptions()),
            self::ATTRIBUTE_IMMUTABLE_FIELDS,
        );
    }

    /**
     * formatOptions is taken raw: an integer carries its min/max there without naming a format.
     *
     * @return array<string, mixed>
     */
    private function immutableFields(UtopiaAttribute $attribute, mixed $formatOptions): array
    {
        return [
            'array' => $attribute->array,
            'signed' => $attribute->signed,
            'format' => $attribute->format?->name,
            'formatOptions' => $formatOptions,
            'filters' => $attribute->filters,
        ];
    }

    /**
     * @return array{UtopiaAttribute, UtopiaAttribute}|null null when either side is not a valid attribute
     */
    private function normalisedAttributes(UtopiaDocument $existing, Column|Attribute $resource, string $type): ?array
    {
        try {
            return [
                UtopiaAttribute::fromDocument($existing)->apply(new AttributeUpdate()),
                $this->resourceAttribute($resource, $type)->apply(new AttributeUpdate()),
            ];
        } catch (DatabaseException) {
            return null;
        }
    }

    private function resourceAttribute(Column|Attribute $resource, string $type): UtopiaAttribute
    {
        return UtopiaAttribute::fromArray([
            'key' => $resource->getKey(),
            'type' => $type,
            'size' => $resource->getSize(),
            'required' => $resource->isRequired(),
            'signed' => $resource->isSigned(),
            'default' => $resource->getDefault(),
            'array' => $resource->isArray(),
            'format' => $resource->getFormat(),
            'formatOptions' => $resource->getFormatOptions(),
            'filters' => $resource->getFilters(),
            'options' => $resource->getOptions() !== [] ? $resource->getOptions() : null,
        ]);
    }

    /** A big integer is stored under either spelling, so an unchanged type is not always an identical string. */
    private function typeMatches(mixed $existing, string $type): bool
    {
        if (!\is_string($existing)) {
            return false;
        }
        if ($existing === $type) {
            return true;
        }

        $stored = $this->storedColumnType($existing);

        return $stored !== null && $stored === $this->storedColumnType($type);
    }

    private function storedColumnType(string $type): ?ColumnType
    {
        try {
            return UtopiaAttribute::typeFromStored($type);
        } catch (StructureException) {
            return null;
        }
    }

    /**
     * `lengths` is compared: the source's own prefix lengths drive the
     * destination index, so a prefix-only difference is a genuine mismatch.
     * The resource carries no TTL, so the deployed one is kept.
     */
    private function indexSpecMatches(UtopiaDocument $existingIdx, Index $resource): bool
    {
        try {
            $deployed = UtopiaIndex::fromDocument($existingIdx);
            $wanted = UtopiaIndex::fromArray([
                'key' => $resource->getKey(),
                'type' => $resource->getType(),
                'attributes' => $resource->getColumns(),
                'lengths' => \array_values($resource->getLengths()),
                'orders' => \array_values($resource->getOrders()),
                'ttl' => $deployed->ttl,
            ]);
        } catch (DatabaseException) {
            return false;
        }

        return $this->indexShape($deployed) === $this->indexShape($wanted);
    }

    /**
     * Only what the index type stores, one length and order per column: "no
     * prefix" is spelled `0` by the resource and `0` or `null` by the metadata
     * collection, and a missing order reads as `null`.
     *
     * @return array<string, mixed>
     */
    private function indexShape(UtopiaIndex $index): array
    {
        $shape = $index->toDocument()->getArrayCopy();
        unset($shape['$id'], $shape['key']);

        $positions = \array_keys($index->attributes);
        if (\array_key_exists('lengths', $shape)) {
            $shape['lengths'] = \array_map(static fn (int $position): int => (int) ($index->lengths[$position] ?? 0), $positions);
        }
        if (\array_key_exists('orders', $shape)) {
            $orders = \array_values($shape['orders']);
            $shape['orders'] = \array_map(static fn (int $position): ?string => $orders[$position] ?? null, $positions);
        }

        return $shape;
    }

    private function tableIdentity(UtopiaDocument $database, UtopiaDocument $table): string
    {
        return $database->getSequence() . ':' . $table->getSequence();
    }

    /**
     * @param array<string, mixed> $options
     * @return array{relatedTable: UtopiaDocument, partnerKey: string, partnerMetaId: string}|null
     */
    private function resolveTwoWayPartner(UtopiaDocument $database, array $options): ?array
    {
        if (empty($options['twoWay'])) {
            return null;
        }
        $relatedTableId = (string) ($options['relatedCollection'] ?? '');
        $partnerKey = (string) ($options['twoWayKey'] ?? '');
        if ($relatedTableId === '' || $partnerKey === '') {
            return null;
        }
        $relatedTable = $this->dbForProject->getDocument(
            $this->databaseCollectionId($database),
            $relatedTableId,
        );
        if ($relatedTable->isEmpty()) {
            return null;
        }
        return [
            'relatedTable' => $relatedTable,
            'partnerKey' => $partnerKey,
            'partnerMetaId' => $this->attributeIndexMetaId($database, $relatedTable, $partnerKey),
        ];
    }

    /** Canonical pair-key — same string regardless of which side is being processed. */
    private function twoWayPairKey(
        UtopiaDocument $database,
        UtopiaDocument $table,
        Column|Attribute $resource,
        string $type,
    ): ?string {
        if ($type !== ColumnType::Relationship->value) {
            return null;
        }
        $options = $resource->getOptions();
        if (empty($options['twoWay'])) {
            return null;
        }
        $twoWayKey = (string) ($options['twoWayKey'] ?? '');
        $relatedTableId = (string) ($options['relatedCollection'] ?? '');
        if ($twoWayKey === '' || $relatedTableId === '') {
            return null;
        }

        $thisSide = $table->getId() . '::' . $resource->getKey();
        $partnerSide = $relatedTableId . '::' . $twoWayKey;
        $pair = [$thisSide, $partnerSide];
        \sort($pair);
        return $database->getSequence() . '@' . \implode('<->', $pair);
    }

    private function databaseCollectionId(UtopiaDocument $database): string
    {
        return 'database_' . $database->getSequence();
    }

    private function tableCollectionId(UtopiaDocument $database, UtopiaDocument $table): string
    {
        return $this->databaseCollectionId($database) . '_collection_' . $table->getSequence();
    }

    private function attributeIndexMetaId(UtopiaDocument $database, UtopiaDocument $table, string $key): string
    {
        return $database->getSequence() . '_' . $table->getSequence() . '_' . $key;
    }

    /** Stale platform/per-database cache shows the pre-change schema; evict after every structural change. */
    private function purgeTableCaches(
        UtopiaDocument $database,
        UtopiaDocument $table,
        UtopiaDatabase $dbForDatabases,
    ): void {
        $this->dbForProject->purgeCachedDocument($this->databaseCollectionId($database), $table->getId());
        $dbForDatabases->purgeCachedCollection($this->tableCollectionId($database, $table));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param list<string> $keys
     */
    private function arraysDifferOnKeys(array $a, array $b, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->valuesMatch($a[$key] ?? null, $b[$key] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * `===` on associative arrays is order-sensitive on keys; ksort both sides
     * before comparing so {min, max} matches {max, min}. Lists (numeric keys)
     * are left alone — order is semantically meaningful for filters/columns.
     */
    private function valuesMatch(mixed $a, mixed $b): bool
    {
        if (\is_array($a) && \is_array($b) && !\array_is_list($a) && !\array_is_list($b)) {
            \ksort($a);
            \ksort($b);
        }
        return $a === $b;
    }

    /** $kind is 'attributeKeys' or 'indexKeys'. */
    private function trackOrphanCandidate(
        UtopiaDocument $database,
        UtopiaDocument $table,
        string $kind,
        string $key,
        UtopiaDatabase $dbForDatabases,
    ): void {
        if ($this->onDuplicate !== OnDuplicate::Overwrite) {
            return;
        }
        $tableId = $this->tableIdentity($database, $table);
        if (!isset($this->orphansByTable[$tableId])) {
            $this->orphansByTable[$tableId] = [
                'database' => $database,
                'table' => $table,
                'dbForDatabases' => $dbForDatabases,
                'attributeKeys' => [],
                'indexKeys' => [],
            ];
        }
        $this->orphansByTable[$tableId][$kind][] = $key;
    }

    /**
     * End-of-migration sweep — only visits tables that had no rows (rest were cleaned per-table in createRecord).
     *
     * @return list<Exception>
     */
    private function cleanupOverwriteOrphans(): array
    {
        $failures = [];
        foreach ($this->orphansByTable as $tableId => $tracked) {
            try {
                $this->cleanupOverwriteOrphansForTable($tableId);
            } catch (\Throwable $error) {
                $failures[] = $this->recordFinalizationFailure(Resource::TYPE_TABLE, $tracked['table']->getId(), $error);
            }
        }

        return $failures;
    }

    /** Called per-table from createRecord before rows land so Structure validator sees the post-cleanup schema. */
    private function cleanupOverwriteOrphansForTable(string $tableId): void
    {
        if ($this->onDuplicate !== OnDuplicate::Overwrite) {
            return;
        }
        if (!isset($this->orphansByTable[$tableId])) {
            return;
        }

        $tracked = $this->orphansByTable[$tableId];
        $database = $tracked['database'];
        $table = $tracked['table'];
        $dbForDatabases = $tracked['dbForDatabases'];

        $this->dropOrphansByKind(
            self::META_ATTRIBUTES,
            $tracked['attributeKeys'],
            $database,
            $table,
            fn (UtopiaDocument $doc) => $this->dropOrphanAttribute($database, $table, $doc, $dbForDatabases),
        );

        $this->dropOrphansByKind(
            self::META_INDEXES,
            $tracked['indexKeys'],
            $database,
            $table,
            fn (UtopiaDocument $doc) => $this->dropOrphanIndex(
                $database,
                $table,
                (string) $doc->getAttribute('key'),
                $dbForDatabases,
            ),
        );

        unset($this->orphansByTable[$tableId]);
    }

    /**
     * @param list<string> $processedKeys
     * @param callable(UtopiaDocument): void $drop
     */
    private function dropOrphansByKind(
        string $metaCollection,
        array $processedKeys,
        UtopiaDocument $database,
        UtopiaDocument $table,
        callable $drop,
    ): void {
        $destDocs = $this->dbForProject->find($metaCollection, [
            Query::equal('databaseInternalId', [$database->getSequence()]),
            Query::equal('collectionInternalId', [$table->getSequence()]),
            Query::limit(PHP_INT_MAX),
        ]);
        foreach ($destDocs as $destDoc) {
            if (!\in_array($destDoc->getAttribute('key'), $processedKeys, true)) {
                $drop($destDoc);
            }
        }
    }

    /** Reads dest's own meta doc as source of truth — there's no source resource for an orphan. */
    private function dropOrphanAttribute(
        UtopiaDocument $database,
        UtopiaDocument $table,
        UtopiaDocument $attrDoc,
        UtopiaDatabase $dbForDatabases,
    ): void {
        $key = (string) $attrDoc->getAttribute('key');
        $type = (string) $attrDoc->getAttribute('type');
        $options = $attrDoc->getAttribute('options', []);
        $collectionId = $this->tableCollectionId($database, $table);

        if ($type === ColumnType::Relationship->value) {
            $this->bestEffort(fn () => $dbForDatabases->deleteRelationship($collectionId, $key));
        } else {
            $this->bestEffort(fn () => $dbForDatabases->deleteAttribute($collectionId, $key));
        }
        $this->bestEffort(fn () => $this->dbForProject->deleteDocument(self::META_ATTRIBUTES, $attrDoc->getId()));
        $this->dbForProject->purgeCachedDocument($this->databaseCollectionId($database), $table->getId());
        $dbForDatabases->purgeCachedCollection($collectionId);

        if ($type !== ColumnType::Relationship->value) {
            return;
        }
        $partner = $this->resolveTwoWayPartner($database, $options);
        if ($partner === null) {
            return;
        }

        // deleteRelationship already dropped the partner physical column; only the Appwrite-level meta doc remains.
        $this->bestEffort(fn () => $this->dbForProject->deleteDocument(self::META_ATTRIBUTES, $partner['partnerMetaId']));
        $this->purgeTableCaches($database, $partner['relatedTable'], $dbForDatabases);
    }

    private function dropOrphanIndex(
        UtopiaDocument $database,
        UtopiaDocument $table,
        string $indexKey,
        UtopiaDatabase $dbForDatabases,
    ): void {
        $collectionId = $this->tableCollectionId($database, $table);
        $indexMetaId = $this->attributeIndexMetaId($database, $table, $indexKey);

        $this->bestEffort(fn () => $dbForDatabases->deleteIndex($collectionId, $indexKey));
        $this->bestEffort(fn () => $this->dbForProject->deleteDocument(self::META_INDEXES, $indexMetaId));
        $this->dbForProject->purgeCachedDocument($this->databaseCollectionId($database), $table->getId());
    }

    /** Swallows deletion errors — a prior run or relationship cascade may have already removed the target. */
    private function bestEffort(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable) {
            // already gone
        }
    }

    /**
     * @throws \Exception
     */
    private function normalizeDateTime(mixed $value, mixed $fallback = null): string
    {
        $resolved = $this->stringifyDateValue($value)
            ?? $this->stringifyDateValue($fallback);

        if (empty($resolved)) {
            return DateTime::format(new \DateTime());
        }

        if (\is_numeric($resolved) && \strlen($resolved) === 10 && (int) $resolved > 0) { // Unix timestamp
            $resolved = '@' . $resolved;
        }

        return DateTime::format(new \DateTime($resolved));
    }

    private function stringifyDateValue(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @throws AppwriteException
     */
    public function importFileResource(Resource $resource): Resource
    {
        switch ($resource->getName()) {
            case Resource::TYPE_FILE:
                /** @var File $resource */
                return $this->importFile($resource);
            case Resource::TYPE_BUCKET:
                /** @var Bucket $resource */

                $compression = match ($resource->getCompression()) {
                    'none' => Compression::NONE(),
                    'gzip' => Compression::GZIP(),
                    'zstd' => Compression::ZSTD(),
                    // no break
                    default => throw new \Exception('Invalid Compression: ' . $resource->getCompression(), Exception::CODE_VALIDATION),
                };

                $response = $this->storage->createBucket(
                    $resource->getId(),
                    $resource->getBucketName(),
                    $resource->getPermissions(),
                    $resource->getFileSecurity(),
                    $resource->getEnabled(),
                    $resource->getMaxFileSize(),
                    $resource->getAllowedFileExtensions(),
                    $compression,
                    $resource->getEncryption(),
                    $resource->getAntiVirus(),
                    $resource->getTransformations()
                );

                $resource->setId($response->id);
        }

        $resource->setStatus(Resource::STATUS_SUCCESS);

        return $resource;
    }

    /**
     * Import File Data
     *
     * @returns File
     * @throws AppwriteException
     */
    public function importFile(File $file): File
    {
        $bucketId = $file->getBucket()->getId();

        $response = null;

        if ($file->getSize() <= Transfer::STORAGE_MAX_CHUNK_SIZE) {
            $response = $this->client->call(
                'POST',
                "/storage/buckets/{$bucketId}/files",
                [
                    'content-type' => 'multipart/form-data',
                    'X-Appwrite-Project' => $this->projectId,
                ],
                [
                    'bucketId' => $bucketId,
                    'fileId' => $file->getId(),
                    'file' => new \CURLFile('data://' . $file->getMimeType() . ';base64,' . base64_encode($file->getData()), $file->getMimeType(), $file->getFileName()),
                    'permissions' => $file->getPermissions(),
                ]
            );

            $file->setStatus(Resource::STATUS_SUCCESS);
            $file->setData('');

            return $file;
        }

        $response = $this->client->call(
            'POST',
            "/storage/buckets/{$bucketId}/files",
            [
                'content-type' => 'multipart/form-data',
                'content-range' => 'bytes ' . ($file->getStart()) . '-' . ($file->getEnd() == ($file->getSize() - 1) ? $file->getSize() : $file->getEnd()) . '/' . $file->getSize(),
                'X-Appwrite-Project' => $this->projectId,
            ],
            [
                'bucketId' => $bucketId,
                'fileId' => $file->getId(),
                'file' => new \CURLFile('data://' . $file->getMimeType() . ';base64,' . base64_encode($file->getData()), $file->getMimeType(), $file->getFileName()),
                'permissions' => $file->getPermissions(),
            ]
        );

        if ($file->getEnd() == ($file->getSize() - 1)) {
            $file->setStatus(Resource::STATUS_SUCCESS);

            // Signatures for encrypted files are invalid, so we skip the check
            if (!$file->getBucket()->getEncryption() || $file->getSize() > (20 * 1024 * 1024)) {
                if (\is_array($response) && $response['signature'] !== $file->getSignature()) {
                    $file->setStatus(Resource::STATUS_WARNING, 'File signature mismatch, Possibly corrupted.');
                }
            }
        }

        $file->setData('');

        return $file;
    }

    /**
     * @throws AppwriteException
     */
    public function importAuthResource(Resource $resource): Resource
    {
        switch ($resource->getName()) {
            case Resource::TYPE_USER:
                /** @var User $resource */
                if (!empty($resource->getPasswordHash())) {
                    $this->importPasswordUser($resource);
                } else {
                    $this->users->create(
                        $resource->getId(),
                        $resource->getEmail(),
                        null,
                        null,
                        null
                    );
                }

                if (!empty($resource->getUsername())) {
                    $this->users->updateName($resource->getId(), $resource->getUsername());
                }

                if (!empty($resource->getPhone())) {
                    $this->users->updatePhone($resource->getId(), $resource->getPhone());
                }

                if ($resource->getEmailVerified()) {
                    $this->users->updateEmailVerification($resource->getId(), true);
                }

                if ($resource->getPhoneVerified()) {
                    $this->users->updatePhoneVerification($resource->getId(), true);
                }

                if ($resource->getDisabled()) {
                    $this->users->updateStatus($resource->getId(), false);
                }

                if (!empty($resource->getPreferences())) {
                    $this->users->updatePrefs($resource->getId(), $resource->getPreferences());
                }

                if (!empty($resource->getLabels())) {
                    $this->users->updateLabels($resource->getId(), $resource->getLabels());
                }

                if (!empty($resource->getTargets())) {
                    $this->importUserTargets($resource->getId(), $resource->getTargets());
                }

                break;
            case Resource::TYPE_TEAM:
                /** @var Team $resource */
                $this->teams->create($resource->getId(), $resource->getTeamName());

                if (!empty($resource->getPreferences())) {
                    $this->teams->updatePrefs($resource->getId(), $resource->getPreferences());
                }
                break;
            case Resource::TYPE_MEMBERSHIP:
                /** @var Membership $resource */
                $user = $resource->getUser();

                $this->teams->createMembership(
                    $resource->getTeam()->getId(),
                    $resource->getRoles(),
                    userId: $user->getId(),
                );
                break;
            case Resource::TYPE_AUTH_METHODS:
                /** @var AuthMethods $resource */
                $this->createAuthMethods($resource);
                break;
            case Resource::TYPE_POLICIES:
                /** @var Policies $resource */
                $this->createPolicies($resource);
                break;
            case Resource::TYPE_OAUTH2_PROVIDER:
                /** @var OAuth2Provider $resource */
                $this->createOAuth2Provider($resource);
                break;
        }

        $resource->setStatus(Resource::STATUS_SUCCESS);

        return $resource;
    }

    /**
     * @param User $user
     * @return \Appwrite\Models\User|null
     * @throws AppwriteException
     * @throws \Exception
     */
    public function importPasswordUser(User $user): ?\Appwrite\Models\User
    {
        $hash = $user->getPasswordHash();
        $result = null;

        if (!$hash) {
            throw new \Exception('Password hash is missing', Exception::CODE_VALIDATION);
        }

        switch ($hash->getAlgorithm()) {
            case Hash::ALGORITHM_SCRYPT_MODIFIED:
                $result = $this->users->createScryptModifiedUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    $hash->getSalt(),
                    $hash->getSeparator(),
                    $hash->getSigningKey(),
                    empty($user->getUsername()) ? null : $user->getUsername()
                );
                break;
            case Hash::ALGORITHM_BCRYPT:
                $result = $this->users->createBcryptUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    empty($user->getUsername()) ? null : $user->getUsername()
                );
                break;
            case Hash::ALGORITHM_ARGON2:
                $result = $this->users->createArgon2User(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    empty($user->getUsername()) ? null : $user->getUsername()
                );
                break;
            case Hash::ALGORITHM_SHA256:
                $result = $this->users->createSHAUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    PasswordHash::SHA256(),
                    empty($user->getUsername()) ? null : $user->getUsername()
                );
                break;
            case Hash::ALGORITHM_PHPASS:
                $result = $this->users->createPHPassUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    empty($user->getUsername()) ? null : $user->getUsername()
                );
                break;
            case Hash::ALGORITHM_SCRYPT:
                $result = $this->users->createScryptUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    $hash->getSalt(),
                    $hash->getPasswordCpu(),
                    $hash->getPasswordMemory(),
                    $hash->getPasswordParallel(),
                    $hash->getPasswordLength(),
                    empty($user->getUsername()) ? null : $user->getUsername()
                );
                break;
            case Hash::ALGORITHM_PLAINTEXT:
                $result = $this->users->create(
                    $user->getId(),
                    $user->getEmail(),
                    $user->getPhone(),
                    $hash->getHash(),
                    empty($user->getUsername()) ? null : $user->getUsername()
                );
                break;
        }

        return $result;
    }

    /**
     * @throws AppwriteException
     */
    public function importFunctionResource(Resource $resource): Resource
    {
        switch ($resource->getName()) {
            case Resource::TYPE_FUNCTION:
                /** @var Func $resource */

                try {
                    $runtime = Runtime::from($resource->getRuntime());
                } catch (\InvalidArgumentException|\ValueError $e) {
                    throw new \Exception('Invalid Runtime: ' . $resource->getRuntime(), Exception::CODE_VALIDATION, $e);
                }

                $this->functions->create(
                    functionId: $resource->getId(),
                    name: $resource->getFunctionName(),
                    runtime: $runtime,
                    execute: $resource->getExecute(),
                    events: $resource->getEvents(),
                    schedule: $resource->getSchedule(),
                    timeout: $resource->getTimeout(),
                    enabled: $resource->getEnabled(),
                    logging: $resource->getLogging(),
                    entrypoint: $resource->getEntrypoint(),
                    commands: $resource->getCommands(),
                    scopes: $resource->getScopes(),
                    buildSpecification: $resource->getBuildSpecification() ?: null,
                    runtimeSpecification: $resource->getRuntimeSpecification() ?: null,
                );
                break;
            case Resource::TYPE_ENVIRONMENT_VARIABLE:
                /** @var EnvVar $resource */
                $this->functions->createVariable(
                    functionId: $resource->getFunc()->getId(),
                    variableId: $resource->getId(),
                    key: $resource->getKey(),
                    value: $resource->getValue(),
                );
                break;
            case Resource::TYPE_DEPLOYMENT:
                /** @var Deployment $resource */
                return $this->importDeployment($resource);
        }

        $resource->setStatus(Resource::STATUS_SUCCESS);

        return $resource;
    }

    public function importBackupResource(Resource $resource): Resource
    {
        $resource->setStatus(Resource::STATUS_SKIPPED);

        return $resource;
    }

    /**
     * @throws AppwriteException
     * @throws \Exception
     */
    private function importDeployment(Deployment $deployment): Resource
    {
        $function = $deployment->getFunction();

        // Deployment API always creates a new deployment, so unlike other resources
        // there's no duplicate detection. Skip if the parent function wasn't imported successfully.
        if ($function->getStatus() !== Resource::STATUS_SUCCESS) {
            $deployment->setStatus(Resource::STATUS_SKIPPED, 'Parent function "' . $function->getId() . '" failed to import');

            return $deployment;
        }

        $functionId = $function->getId();

        $response = null;

        if ($deployment->getSize() <= Transfer::STORAGE_MAX_CHUNK_SIZE) {
            $response = $this->client->call(
                'POST',
                "/functions/{$functionId}/deployments",
                [
                    'content-type' => 'multipart/form-data',
                    'X-Appwrite-Project' => $this->projectId,
                ],
                [
                    'functionId' => $functionId,
                    'code' => new \CURLFile('data://application/gzip;base64,' . base64_encode($deployment->getData()), 'application/gzip', 'deployment.tar.gz'),
                    'activate' => $deployment->getActivated() ? 'true' : 'false',
                    'entrypoint' => $deployment->getEntrypoint(),
                ]
            );

            $deployment->setStatus(Resource::STATUS_SUCCESS);

            return $deployment;
        }

        $response = $this->client->call(
            'POST',
            "/v1/functions/{$functionId}/deployments",
            [
                'content-type' => 'multipart/form-data',
                'content-range' => 'bytes ' . ($deployment->getStart()) . '-' . ($deployment->getEnd() == ($deployment->getSize() - 1) ? $deployment->getSize() : $deployment->getEnd()) . '/' . $deployment->getSize(),
                'x-appwrite-id' => $deployment->getId(),
                'X-Appwrite-Project' => $this->projectId,
            ],
            [
                'functionId' => $functionId,
                'code' => new \CURLFile('data://application/gzip;base64,' . base64_encode($deployment->getData()), 'application/gzip', 'deployment.tar.gz'),
                'activate' => $deployment->getActivated() ? 'true' : 'false',
                'entrypoint' => $deployment->getEntrypoint(),
            ]
        );

        if (!\is_array($response) || !isset($response['$id'])) {
            throw new \Exception('Deployment creation failed', Exception::CODE_INTERNAL);
        }

        if ($deployment->getStart() === 0) {
            $deployment->setId($response['$id']);
        }

        if ($deployment->getEnd() == ($deployment->getSize() - 1)) {
            $deployment->setStatus(Resource::STATUS_SUCCESS);
        } else {
            $deployment->setStatus(Resource::STATUS_PENDING);
        }

        return $deployment;
    }

    /**
     * @throws AppwriteException
     * @throws \Exception
     */
    public function importMessagingResource(Resource $resource): Resource
    {
        switch ($resource->getName()) {
            case Resource::TYPE_PROVIDER:
                /** @var Provider $resource */
                $this->createProvider($resource);
                break;
            case Resource::TYPE_TOPIC:
                /** @var Topic $resource */
                $this->createTopic($resource);
                break;
            case Resource::TYPE_SUBSCRIBER:
                /** @var Subscriber $resource */
                $this->createSubscriber($resource);
                break;
            case Resource::TYPE_MESSAGE:
                /** @var Message $resource */
                $this->createMessage($resource);
                break;
            default:
                throw new \Exception('Unknown messaging resource type: ' . $resource->getName());
        }

        $resource->setStatus(Resource::STATUS_SUCCESS);

        return $resource;
    }

    /**
     * @throws AppwriteException
     */
    public function importSiteResource(Resource $resource): Resource
    {
        switch ($resource->getName()) {
            case Resource::TYPE_SITE:
                /** @var Site $resource */

                try {
                    $buildRuntime = BuildRuntime::from($resource->getBuildRuntime());
                } catch (\InvalidArgumentException|\ValueError $e) {
                    throw new \Exception('Invalid Build Runtime: ' . $resource->getBuildRuntime(), Exception::CODE_VALIDATION, $e);
                }

                $framework = match ($resource->getFramework()) {
                    'analog' => Framework::ANALOG(),
                    'angular' => Framework::ANGULAR(),
                    'astro' => Framework::ASTRO(),
                    'flutter', 'flutter-web' => Framework::FLUTTER(),
                    'lynx' => Framework::LYNX(),
                    'nextjs' => Framework::NEXTJS(),
                    'nuxt' => Framework::NUXT(),
                    'react' => Framework::REACT(),
                    'react-native' => Framework::REACTNATIVE(),
                    'remix' => Framework::REMIX(),
                    'svelte-kit' => Framework::SVELTEKIT(),
                    'tanstack-start' => Framework::TANSTACKSTART(),
                    'vite' => Framework::VITE(),
                    'vue' => Framework::VUE(),
                    default => Framework::OTHER(),
                };

                $adapter = match ($resource->getAdapter()) {
                    'static' => Adapter::STATIC(),
                    'ssr' => Adapter::SSR(),
                    default => null,
                };

                $this->sites->create(
                    siteId: $resource->getId(),
                    name: $resource->getSiteName(),
                    framework: $framework,
                    buildRuntime: $buildRuntime,
                    enabled: $resource->getEnabled(),
                    logging: $resource->getLogging(),
                    timeout: $resource->getTimeout(),
                    installCommand: $resource->getInstallCommand(),
                    buildCommand: $resource->getBuildCommand(),
                    outputDirectory: $resource->getOutputDirectory(),
                    adapter: $adapter,
                    fallbackFile: $resource->getFallbackFile(),
                    buildSpecification: $resource->getBuildSpecification() ?: null,
                    runtimeSpecification: $resource->getRuntimeSpecification() ?: null,
                );
                break;
            case Resource::TYPE_SITE_VARIABLE:
                /** @var SiteEnvVar $resource */
                $this->sites->createVariable(
                    siteId: $resource->getSite()->getId(),
                    variableId: $resource->getId(),
                    key: $resource->getKey(),
                    value: $resource->getValue(),
                );
                break;
            case Resource::TYPE_SITE_DEPLOYMENT:
                /** @var SiteDeployment $resource */
                return $this->importSiteDeployment($resource);
        }

        $resource->setStatus(Resource::STATUS_SUCCESS);

        return $resource;
    }

    /**
     * Import user targets not auto-created by the server (e.g. push).
     * providerInternalId is resolved later in createProvider().
     *
     * @param array<array<string, mixed>> $targets
     */
    protected function importUserTargets(string $userId, array $targets): void
    {
        $userDoc = null;

        foreach ($targets as $target) {
            switch ($target['providerType'] ?? '') {
                case 'email':
                case 'sms':
                    // Auto-created by the server when a user is created with an email/phone
                    break;
                case 'push':
                    $userDoc ??= $this->dbForProject->getDocument('users', $userId);

                    $createdAt = $this->normalizeDateTime($target['$createdAt'] ?? null);
                    $updatedAt = $this->normalizeDateTime($target['$updatedAt'] ?? null, $createdAt);

                    $this->dbForProject->createDocument('targets', new UtopiaDocument([
                        '$id' => $target['$id'],
                        '$createdAt' => $createdAt,
                        '$updatedAt' => $updatedAt,
                        '$permissions' => [
                            Permission::read(Role::user($userId)),
                            Permission::update(Role::user($userId)),
                            Permission::delete(Role::user($userId)),
                        ],
                        'userId' => $userId,
                        'userInternalId' => $userDoc->getSequence(),
                        'providerType' => $target['providerType'],
                        'providerId' => $target['providerId'] ?? null,
                        'identifier' => $target['identifier'],
                        'name' => $target['name'] ?? null,
                        'expired' => $target['expired'] ?? false,
                    ]));
                    break;
            }
        }

        if ($userDoc !== null) {
            $this->dbForProject->purgeCachedDocument('users', $userId);
        }
    }

    /**
     * @throws AppwriteException
     * @throws \Exception
     */
    protected function createProvider(Provider $resource): void
    {
        $credentials = $resource->getCredentials();
        $options = $resource->getOptions();
        $id = $resource->getId();
        $name = $resource->getProviderName();
        $enabled = $resource->getEnabled();

        match ($resource->getProvider()) {
            'mailgun' => $this->messaging->createMailgunProvider(
                $id,
                $name,
                $credentials['apiKey'] ?? null,
                $credentials['domain'] ?? null,
                $credentials['isEuRegion'] ?? null,
                ($options['fromName'] ?? '') ?: null,
                ($options['fromEmail'] ?? '') ?: null,
                ($options['replyToName'] ?? '') ?: null,
                ($options['replyToEmail'] ?? '') ?: null,
                $enabled,
            ),
            'sendgrid' => $this->messaging->createSendgridProvider(
                $id,
                $name,
                $credentials['apiKey'] ?? null,
                ($options['fromName'] ?? '') ?: null,
                ($options['fromEmail'] ?? '') ?: null,
                ($options['replyToName'] ?? '') ?: null,
                ($options['replyToEmail'] ?? '') ?: null,
                $enabled,
            ),
            'resend' => $this->messaging->createResendProvider(
                $id,
                $name,
                $credentials['apiKey'] ?? null,
                ($options['fromName'] ?? '') ?: null,
                ($options['fromEmail'] ?? '') ?: null,
                ($options['replyToName'] ?? '') ?: null,
                ($options['replyToEmail'] ?? '') ?: null,
                $enabled,
            ),
            'smtp' => $this->messaging->createSMTPProvider(
                $id,
                $name,
                $credentials['host'] ?? '',
                $credentials['port'] ?? null,
                ($credentials['username'] ?? '') ?: null,
                ($credentials['password'] ?? '') ?: null,
                match ($options['encryption'] ?? '') {
                    'ssl' => SmtpEncryption::SSL(),
                    'tls' => SmtpEncryption::TLS(),
                    default => SmtpEncryption::NONE(),
                },
                $options['autoTLS'] ?? null,
                ($options['mailer'] ?? '') ?: null,
                ($options['fromName'] ?? '') ?: null,
                ($options['fromEmail'] ?? '') ?: null,
                ($options['replyToName'] ?? '') ?: null,
                ($options['replyToEmail'] ?? '') ?: null,
                $enabled,
            ),
            'msg91' => $this->messaging->createMsg91Provider(
                $id,
                $name,
                $credentials['templateId'] ?? null,
                $credentials['senderId'] ?? null,
                $credentials['authKey'] ?? null,
                $enabled,
            ),
            'telesign' => $this->messaging->createTelesignProvider(
                $id,
                $name,
                ($options['from'] ?? '') ?: null,
                $credentials['customerId'] ?? null,
                $credentials['apiKey'] ?? null,
                $enabled,
            ),
            'textmagic' => $this->messaging->createTextmagicProvider(
                $id,
                $name,
                ($options['from'] ?? '') ?: null,
                $credentials['username'] ?? null,
                $credentials['apiKey'] ?? null,
                $enabled,
            ),
            'twilio' => $this->messaging->createTwilioProvider(
                $id,
                $name,
                ($options['from'] ?? '') ?: null,
                $credentials['accountSid'] ?? null,
                $credentials['authToken'] ?? null,
                $enabled,
            ),
            'vonage' => $this->messaging->createVonageProvider(
                $id,
                $name,
                ($options['from'] ?? '') ?: null,
                $credentials['apiKey'] ?? null,
                $credentials['apiSecret'] ?? null,
                $enabled,
            ),
            'fcm' => $this->messaging->createFCMProvider(
                $id,
                $name,
                $credentials['serviceAccountJSON'] ?? null,
                $enabled,
            ),
            'apns' => $this->messaging->createAPNSProvider(
                $id,
                $name,
                $credentials['authKey'] ?? null,
                $credentials['authKeyId'] ?? null,
                $credentials['teamId'] ?? null,
                $credentials['bundleId'] ?? null,
                $options['sandbox'] ?? null,
                $enabled,
            ),
            default => throw new \Exception('Unknown provider: ' . $resource->getProvider()),
        };

        // Resolve providerInternalId for push targets that were written during GROUP_AUTH
        // before the provider existed on the destination.
        $provider = $this->dbForProject->getDocument('providers', $id);
        $targets = $this->dbForProject->find('targets', [
            Query::equal('providerId', [$id]),
            Query::isNull('providerInternalId'),
        ]);

        $userIds = [];

        foreach ($targets as $target) {
            $target->setAttribute('providerInternalId', $provider->getSequence());
            $this->dbForProject->updateDocument('targets', $target->getId(), $target);
            $userIds[$target->getAttribute('userId')] = true;
        }

        foreach (array_keys($userIds) as $userId) {
            $this->dbForProject->purgeCachedDocument('users', $userId);
        }
    }

    /**
     * @throws AppwriteException
     */
    protected function createTopic(Topic $resource): void
    {
        $this->messaging->createTopic(
            $resource->getId(),
            $resource->getTopicName(),
            $resource->getSubscribe(),
        );
    }

    /**
     * @throws AuthorizationException
     * @throws StructureException
     * @throws DatabaseException|\Exception
     */
    protected function createSubscriber(Subscriber $resource): void
    {
        $target = match ($resource->getProviderType()) {
            'push' => $this->dbForProject->getDocument('targets', $resource->getTargetId()),
            'email', 'sms' => $this->dbForProject->findOne('targets', [
                Query::equal('userId', [$resource->getUserId()]),
                Query::equal('providerType', [$resource->getProviderType()]),
            ]),
            default => throw new \Exception('Unknown provider type: ' . $resource->getProviderType()),
        };

        if (!$target || $target->isEmpty()) {
            throw new \Exception('Target not found for subscriber: ' . $resource->getId());
        }

        $topic = $this->dbForProject->getDocument('topics', $resource->getTopicId());
        if ($topic->isEmpty()) {
            throw new \Exception('Topic not found: ' . $resource->getTopicId());
        }

        $user = $this->dbForProject->getDocument('users', $resource->getUserId());
        if ($user->isEmpty()) {
            throw new \Exception('User not found: ' . $resource->getUserId());
        }

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);

        $this->dbForProject->createDocument('subscribers', new UtopiaDocument([
            '$id' => $resource->getId(),
            '$createdAt' => $createdAt,
            '$updatedAt' => $updatedAt,
            '$permissions' => [
                Permission::read(Role::user($resource->getUserId())),
                Permission::delete(Role::user($resource->getUserId())),
            ],
            'topicId' => $topic->getId(),
            'topicInternalId' => $topic->getSequence(),
            'targetId' => $target->getId(),
            'targetInternalId' => $target->getSequence(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'providerType' => $resource->getProviderType(),
            'search' => implode(' ', [
                $resource->getId(),
                $target->getId(),
                $user->getId(),
                $resource->getProviderType(),
            ]),
        ]));

        $totalAttribute = match ($resource->getProviderType()) {
            'email' => 'emailTotal',
            'sms' => 'smsTotal',
            'push' => 'pushTotal',
            default => throw new \Exception('Unknown provider type: ' . $resource->getProviderType()),
        };

        $this->dbForProject->increaseDocumentAttribute('topics', $resource->getTopicId(), $totalAttribute);
    }

    /**
     * @throws AppwriteException
     * @throws \Exception
     */
    protected function createMessage(Message $resource): void
    {
        $resolvedTargets = $resource->getTargets();
        $status = $resource->getMessageStatus();

        if ($status === 'scheduled') {
            $scheduledAt = $resource->getScheduledAt();

            if (!empty($scheduledAt) && new \DateTime($scheduledAt) > new \DateTime()) {
                $this->createScheduledMessage($resource, $resolvedTargets);

                return;
            }

            $status = 'draft';
        }

        if ($status === 'processing') {
            $status = 'draft';
        }

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);

        $data = $resource->getData();
        $searchContent = match ($resource->getProviderType()) {
            'email' => $data['subject'] ?? '',
            'sms' => $data['content'] ?? '',
            'push' => $data['title'] ?? '',
            default => '',
        };

        $this->dbForProject->createDocument('messages', new UtopiaDocument([
            '$id' => $resource->getId(),
            '$createdAt' => $createdAt,
            '$updatedAt' => $updatedAt,
            '$permissions' => [],
            'providerType' => $resource->getProviderType(),
            'topics' => $resource->getTopics(),
            'users' => $resource->getUsers(),
            'targets' => $resolvedTargets,
            'scheduledAt' => null,
            'deliveredAt' => $resource->getDeliveredAt() ?: null,
            'deliveryErrors' => $resource->getDeliveryErrors(),
            'deliveredTotal' => $resource->getDeliveredTotal(),
            'data' => $data,
            'status' => $status,
            'search' => implode(' ', array_filter([
                $resource->getId(),
                $data['description'] ?? '',
                $status,
                $searchContent,
                $resource->getProviderType(),
            ])),
        ]));
    }

    /**
     * @param array<string> $resolvedTargets
     * @throws AppwriteException
     * @throws \Exception
     */
    protected function createScheduledMessage(Message $resource, array $resolvedTargets): void
    {
        $data = $resource->getData();
        $topics = $resource->getTopics() ?: null;
        $users = $resource->getUsers() ?: null;
        $targets = $resolvedTargets ?: null;
        $scheduledAt = $resource->getScheduledAt();

        match ($resource->getProviderType()) {
            'email' => $this->messaging->createEmail(
                $resource->getId(),
                $data['subject'] ?? '',
                $data['content'] ?? '',
                $topics,
                $users,
                $targets,
                $data['cc'] ?? null,
                $data['bcc'] ?? null,
                null,
                false,
                $data['html'] ?? null,
                $scheduledAt,
            ),
            'sms' => $this->messaging->createSMS(
                $resource->getId(),
                $data['content'] ?? '',
                $topics,
                $users,
                $targets,
                false,
                $scheduledAt,
            ),
            'push' => $this->messaging->createPush(
                $resource->getId(),
                $data['title'] ?? null,
                $data['body'] ?? null,
                $topics,
                $users,
                $targets,
                $data['data'] ?? null,
                $data['action'] ?? null,
                $data['image'] ?? null,
                $data['icon'] ?? null,
                $data['sound'] ?? null,
                $data['color'] ?? null,
                $data['tag'] ?? null,
                $data['badge'] ?? null,
                false,
                $scheduledAt,
                $data['contentAvailable'] ?? null,
                $data['critical'] ?? null,
                null,
            ),
            default => throw new \Exception('Unknown provider type: ' . $resource->getProviderType()),
        };
    }

    private function importSiteDeployment(SiteDeployment $deployment): Resource
    {
        $site = $deployment->getSite();

        // Deployment API always creates a new deployment, so unlike other resources
        // there's no duplicate detection. Skip if the parent site wasn't imported successfully.
        if ($site->getStatus() !== Resource::STATUS_SUCCESS) {
            $deployment->setStatus(Resource::STATUS_SKIPPED, 'Parent site "' . $site->getId() . '" failed to import');

            return $deployment;
        }

        $siteId = $site->getId();

        if ($deployment->getSize() <= Transfer::STORAGE_MAX_CHUNK_SIZE) {
            $response = $this->client->call(
                'POST',
                "/sites/{$siteId}/deployments",
                [
                    'content-type' => 'multipart/form-data',
                    'X-Appwrite-Project' => $this->projectId,
                ],
                [
                    'siteId' => $siteId,
                    'code' => new \CURLFile('data://application/gzip;base64,' . base64_encode($deployment->getData()), 'application/gzip', 'deployment.tar.gz'),
                    'activate' => $deployment->getActivated() ? 'true' : 'false',
                ]
            );

            if (!\is_array($response) || !isset($response['$id'])) {
                throw new \Exception('Site deployment creation failed', Exception::CODE_INTERNAL);
            }

            $deployment->setStatus(Resource::STATUS_SUCCESS);

            return $deployment;
        }

        $response = $this->client->call(
            'POST',
            "/sites/{$siteId}/deployments",
            [
                'content-type' => 'multipart/form-data',
                'content-range' => 'bytes ' . ($deployment->getStart()) . '-' . ($deployment->getEnd() == ($deployment->getSize() - 1) ? $deployment->getSize() : $deployment->getEnd()) . '/' . $deployment->getSize(),
                'x-appwrite-id' => $deployment->getId(),
                'X-Appwrite-Project' => $this->projectId,
            ],
            [
                'siteId' => $siteId,
                'code' => new \CURLFile('data://application/gzip;base64,' . base64_encode($deployment->getData()), 'application/gzip', 'deployment.tar.gz'),
                'activate' => $deployment->getActivated() ? 'true' : 'false',
            ]
        );

        if (!\is_array($response) || !isset($response['$id'])) {
            throw new \Exception('Site deployment creation failed', Exception::CODE_INTERNAL);
        }

        if ($deployment->getStart() === 0) {
            $deployment->setId($response['$id']);
        }

        if ($deployment->getEnd() == ($deployment->getSize() - 1)) {
            $deployment->setStatus(Resource::STATUS_SUCCESS);
        } else {
            $deployment->setStatus(Resource::STATUS_PENDING);
        }

        return $deployment;
    }

    /**
     * @throws \Exception
     */
    public function importIntegrationsResource(Resource $resource): Resource
    {
        switch ($resource->getName()) {
            case Resource::TYPE_PLATFORM:
                /** @var Platform $resource */
                $this->createPlatform($resource);
                break;
            case Resource::TYPE_API_KEY:
                /** @var ApiKey $resource */
                $this->createApiKey($resource);
                break;
            case Resource::TYPE_WEBHOOK:
                /** @var Webhook $resource */
                $this->createWebhook($resource);
                break;
            case Resource::TYPE_SMTP:
                /** @var SMTP $resource */
                $this->createSMTP($resource);
                break;
        }

        if ($resource->getStatus() !== Resource::STATUS_SKIPPED) {
            $resource->setStatus(Resource::STATUS_SUCCESS);
        }

        return $resource;
    }

    public function importProjectsResource(Resource $resource): Resource
    {
        switch ($resource->getName()) {
            case Resource::TYPE_PROJECT_VARIABLE:
                /** @var ProjectVariable $resource */
                $this->createProjectVariable($resource);
                break;
            case Resource::TYPE_PROJECT_PROTOCOLS:
                /** @var Protocols $resource */
                $this->createProtocols($resource);
                break;
            case Resource::TYPE_PROJECT_LABELS:
                /** @var Labels $resource */
                $this->createLabels($resource);
                break;
            case Resource::TYPE_PROJECT_SERVICES:
                /** @var ServicesResource $resource */
                $this->createServices($resource);
                break;
            case Resource::TYPE_PROJECT_EMAIL_TEMPLATE:
                /** @var EmailTemplate $resource */
                $this->createEmailTemplate($resource);
                break;
        }

        if ($resource->getStatus() !== Resource::STATUS_SKIPPED) {
            $resource->setStatus(Resource::STATUS_SUCCESS);
        }

        return $resource;
    }

    public function importDomainsResource(Resource $resource): Resource
    {
        switch ($resource->getName()) {
            case Resource::TYPE_RULE:
                /** @var Rule $resource */
                $success = $this->createRule($resource);
                if (!$success) {
                    return $resource;
                }
                break;
        }

        if ($resource->getStatus() !== Resource::STATUS_SKIPPED) {
            $resource->setStatus(Resource::STATUS_SUCCESS);
        }

        return $resource;
    }

    protected function createProjectVariable(ProjectVariable $resource): bool
    {
        $key = $resource->getKey();

        if (
            \strlen($key) > UtopiaDatabase::LENGTH_KEY
            || \preg_match(self::VARIABLE_KEY_PATTERN, $key) !== 1
        ) {
            $resource->setStatus(
                Resource::STATUS_SKIPPED,
                'Project variable key is not a valid environment variable name'
            );

            return false;
        }

        $existing = $this->dbForProject->findOne('variables', [
            Query::equal('resourceType', ['project']),
            Query::equal('key', [$key]),
        ]);

        if ($existing !== false && !$existing->isEmpty()) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'Project variable already exists');
            return false;
        }

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);
        $variableId = Id::unique();

        try {
            $this->dbForProject->createDocument('variables', new UtopiaDocument([
                '$id' => $variableId,
                '$permissions' => $resource->getPermissions(),
                'resourceInternalId' => '',
                'resourceId' => '',
                'resourceType' => 'project',
                'key' => $key,
                'value' => $resource->getValue(),
                'secret' => $resource->isSecret(),
                'search' => \implode(' ', [$variableId, $key, 'project']),
                '$createdAt' => $createdAt,
                '$updatedAt' => $updatedAt,
            ]));
        } catch (DuplicateException) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'Project variable already exists');
            return false;
        }

        return true;
    }

    protected function createProtocols(Protocols $resource): bool
    {
        $project = $this->dbForPlatform->getDocument('projects', $this->projectId);
        $apis = $project->getAttribute('apis', []);

        $apis[(string) ProjectProtocolId::REST()]      = $resource->getRest();
        $apis[(string) ProjectProtocolId::GRAPHQL()]   = $resource->getGraphql();
        $apis[(string) ProjectProtocolId::WEBSOCKET()] = $resource->getWebsocket();

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument(
            'projects',
            $this->projectId,
            new UtopiaDocument(['apis' => $apis]),
        ));

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    protected function createLabels(Labels $resource): bool
    {
        $labels = \array_values(\array_unique($resource->getLabels()));

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument(
            'projects',
            $this->projectId,
            new UtopiaDocument(['labels' => $labels]),
        ));

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    protected function createServices(ServicesResource $resource): bool
    {
        $project = $this->dbForPlatform->getDocument('projects', $this->projectId);
        $services = $project->getAttribute('services', []);

        foreach ($resource->getServices() as $serviceId => $enabled) {
            $services[$serviceId] = (bool) $enabled;
        }

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument(
            'projects',
            $this->projectId,
            new UtopiaDocument(['services' => $services]),
        ));

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    /**
     * Password is intentionally not copied — source API never exposes it.
     * Read-then-merge preserves the destination's existing password.
     */
    protected function createSMTP(SMTP $resource): bool
    {
        $project = $this->dbForPlatform->getDocument('projects', $this->projectId);
        $smtp = $project->getAttribute('smtp', []);

        $smtp['enabled']      = $resource->getEnabled();
        $smtp['senderName']   = $resource->getSenderName();
        $smtp['senderEmail']  = $resource->getSenderEmail();
        $smtp['replyToName']  = $resource->getReplyToName();
        $smtp['replyToEmail'] = $resource->getReplyToEmail();
        $smtp['host']         = $resource->getHost();
        $smtp['port']         = $resource->getPort();
        $smtp['username']     = $resource->getUsername();
        $smtp['secure']       = $resource->getSecure();

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument(
            'projects',
            $this->projectId,
            new UtopiaDocument(['smtp' => $smtp]),
        ));

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    /**
     * Direct DB write rather than the SDK's updateEmailTemplate, which rejects
     * the call unless custom SMTP is enabled — templates may migrate before SMTP.
     * Read-then-merge preserves templates already on the destination.
     */
    protected function createEmailTemplate(EmailTemplate $resource): bool
    {
        $project = $this->dbForPlatform->getDocument('projects', $this->projectId);
        $templates = $project->getAttribute('templates', []);

        $key = 'email.' . $resource->getTemplateId() . '-' . $resource->getLocale();
        $existing = $templates[$key] ?? [];

        $existing['subject']      = $resource->getSubject();
        $existing['message']      = $resource->getBody();
        $existing['senderName']   = $resource->getSenderName();
        $existing['senderEmail']  = $resource->getSenderEmail();
        $existing['replyToEmail'] = $resource->getReplyToEmail();
        $existing['replyToName']  = $resource->getReplyToName();

        $templates[$key] = $existing;

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument(
            'projects',
            $this->projectId,
            new UtopiaDocument(['templates' => $templates]),
        ));

        return true;
    }

    /**
     * Auto-generated rules (default `.appwrite.network` domains for functions/sites)
     * are recreated automatically on the destination when the parent Function/Site
     * is migrated, so only manual rules need to be imported.
     *
     * Function/site IDs are preserved across migration, so the source
     * `deploymentResourceId` is passed through directly.
     */
    protected function createRule(Rule $resource): bool
    {
        if ($resource->getTrigger() !== 'manual') {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'Auto-generated rule, recreated by parent resource migration');
            return false;
        }

        $type = $resource->getType();
        $deploymentResourceType = $resource->getDeploymentResourceType();
        $branch = $resource->getDeploymentVcsProviderBranch();

        try {
            switch ($type) {
                case 'api':
                    $this->proxy->createAPIRule($resource->getDomain());
                    break;

                case 'redirect':
                    $statusCode = match ($resource->getRedirectStatusCode()) {
                        301 => StatusCode::MOVEDPERMANENTLY(),
                        302 => StatusCode::FOUND(),
                        307 => StatusCode::TEMPORARYREDIRECT(),
                        308 => StatusCode::PERMANENTREDIRECT(),
                        // no break
                        default => StatusCode::MOVEDPERMANENTLY(),
                    };

                    $resourceType = $deploymentResourceType === 'site'
                        ? ProxyResourceType::SITE()
                        : ProxyResourceType::FUNCTIONMODEL();

                    $this->proxy->createRedirectRule(
                        $resource->getDomain(),
                        $resource->getRedirectUrl(),
                        $statusCode,
                        $resource->getDeploymentResourceId(),
                        $resourceType,
                    );
                    break;

                case 'deployment':
                    if ($deploymentResourceType === 'function') {
                        $this->proxy->createFunctionRule(
                            $resource->getDomain(),
                            $resource->getDeploymentResourceId(),
                            $branch !== '' ? $branch : null,
                        );
                    } elseif ($deploymentResourceType === 'site') {
                        $this->proxy->createSiteRule(
                            $resource->getDomain(),
                            $resource->getDeploymentResourceId(),
                            $branch !== '' ? $branch : null,
                        );
                    } else {
                        $resource->setStatus(Resource::STATUS_SKIPPED, 'Unsupported deployment resource type "' . $deploymentResourceType . '"');
                        return false;
                    }
                    break;

                default:
                    $resource->setStatus(Resource::STATUS_SKIPPED, 'Unsupported rule type "' . $type . '"');
                    return false;
            }
        } catch (AppwriteException $e) {
            // 409 means the domain is owned by another project/organization — the
            // user has to release it there before re-running. Surface as a warning,
            // not an error, so the rest of the migration continues.
            if ($e->getCode() === 409) {
                $resource->setStatus(Resource::STATUS_WARNING, 'Domain "' . $resource->getDomain() . '" is owned by another project. Remove it there and re-run the migration.');
                return false;
            }

            throw $e;
        }

        return true;
    }

    protected function createWebhook(Webhook $resource): bool
    {
        $existing = $this->dbForPlatform->findOne('webhooks', [
            Query::equal('projectInternalId', [$this->projectInternalId]),
            Query::equal('name', [$resource->getWebhookName()]),
        ]);

        if ($existing !== false && !$existing->isEmpty()) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'Webhook already exists');
            return false;
        }

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);

        try {
            $this->dbForPlatform->createDocument('webhooks', new UtopiaDocument([
                '$id' => Id::unique(),
                '$permissions' => $resource->getPermissions(),
                'projectInternalId' => $this->projectInternalId,
                'projectId' => $this->projectId,
                'name' => $resource->getWebhookName(),
                'events' => $resource->getEvents(),
                'url' => $resource->getUrl(),
                'security' => $resource->getSecurity(),
                'httpUser' => $resource->getHttpUser(),
                'httpPass' => $resource->getHttpPass(),
                // SDK only returns the signing secret on creation, never on list — regenerate
                // a fresh one on the destination to match upstream createWebhook behavior.
                'signatureKey' => \bin2hex(\random_bytes(64)),
                'enabled' => $resource->isEnabled(),
                '$createdAt' => $createdAt,
                '$updatedAt' => $updatedAt,
            ]));
        } catch (DuplicateException) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'Webhook already exists');
            return false;
        }

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    /**
     * @throws \Throwable
     */
    protected function createPlatform(Platform $resource): bool
    {
        $existing = $this->dbForPlatform->findOne('platforms', [
            Query::equal('projectId', [$this->projectId]),
            Query::equal('type', [$resource->getType()]),
            Query::equal('name', [$resource->getPlatformName()]),
        ]);

        if ($existing !== false && !$existing->isEmpty()) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'Platform already exists');
            return false;
        }

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);

        try {
            $this->dbForPlatform->createDocument('platforms', new UtopiaDocument([
                '$id' => Id::unique(),
                '$permissions' => $resource->getPermissions(),
                'projectInternalId' => $this->projectInternalId,
                'projectId' => $this->projectId,
                'type' => $resource->getType(),
                'name' => $resource->getPlatformName(),
                'key' => $resource->getKey(),
                'store' => $resource->getStore(),
                'hostname' => $resource->getHostname(),
                '$createdAt' => $createdAt,
                '$updatedAt' => $updatedAt,
            ]));
        } catch (DuplicateException) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'Platform already exists');
            return false;
        }

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    /**
     * Storage keys mirror app/config/auth.php, not the SDK enum values.
     * Shares the `auths` map with createPolicies — read-then-merge.
     */
    protected function createAuthMethods(AuthMethods $resource): bool
    {
        $project = $this->dbForPlatform->getDocument('projects', $this->projectId);
        $auths = $project->getAttribute('auths', []);

        $auths['emailPassword']     = $resource->getEmailPassword();
        $auths['usersAuthMagicURL'] = $resource->getMagicURL();
        $auths['emailOtp']          = $resource->getEmailOtp();
        $auths['anonymous']         = $resource->getAnonymous();
        $auths['invites']           = $resource->getInvites();
        $auths['JWT']               = $resource->getJwt();
        $auths['phone']             = $resource->getPhone();

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument(
            'projects',
            $this->projectId,
            new UtopiaDocument(['auths' => $auths]),
        ));

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    protected function createOAuth2Provider(OAuth2Provider $resource): bool
    {
        $key = $resource->getProviderKey();
        $project = $this->dbForPlatform->getDocument('projects', $this->projectId);
        $oAuthProviders = $project->getAttribute('oAuthProviders', []);

        $appId = $resource->getDestinationAppId();
        if ($appId !== null) {
            $oAuthProviders[$key . 'Appid'] = $appId;
        }

        $secretFields = $resource->getDestinationSecretFields();
        if (!empty($secretFields)) {
            $oAuthProviders[$key . 'Secret'] = $this->mergeJsonSecret(
                $oAuthProviders[$key . 'Secret'] ?? '',
                $secretFields,
            );
        }

        $oAuthProviders[$key . 'Enabled'] = $resource->getEnabled();

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument(
            'projects',
            $this->projectId,
            new UtopiaDocument(['oAuthProviders' => $oAuthProviders]),
        ));

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function mergeJsonSecret(string $existing, array $fields): string
    {
        if (empty($fields)) {
            return $existing;
        }

        $decoded = $existing === '' ? [] : (\json_decode($existing, true) ?: []);
        if (!\is_array($decoded)) {
            $decoded = [];
        }
        foreach ($fields as $name => $value) {
            $decoded[$name] = $value;
        }

        return \json_encode($decoded) ?: '';
    }

    /**
     * Direct DB write — SDK policy setters reject `total: 0` but `0` is the
     * storage value for "disabled". Shares the `auths` map with
     * createAuthMethods — read-then-merge.
     */
    protected function createPolicies(Policies $resource): bool
    {
        $project = $this->dbForPlatform->getDocument('projects', $this->projectId);
        $auths = $project->getAttribute('auths', []);

        $auths['passwordHistory'] = $resource->getPasswordHistory();
        $auths['duration']        = $resource->getSessionDuration();
        $auths['maxSessions']     = $resource->getSessionsLimit();
        $auths['limit']           = $resource->getUserLimit();

        $auths['passwordDictionary'] = $resource->getPasswordDictionary();
        $auths['personalDataCheck']  = $resource->getPersonalDataCheck();
        $auths['sessionAlerts']      = $resource->getSessionAlerts();
        $auths['invalidateSessions'] = $resource->getSessionInvalidation();

        $auths['membershipsUserId']    = $resource->getMembershipsUserId();
        $auths['membershipsUserEmail'] = $resource->getMembershipsUserEmail();
        $auths['membershipsUserName']  = $resource->getMembershipsUserName();
        $auths['membershipsMfa']       = $resource->getMembershipsUserMfa();
        $auths['membershipsUserPhone'] = $resource->getMembershipsUserPhone();

        $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocument(
            'projects',
            $this->projectId,
            new UtopiaDocument(['auths' => $auths]),
        ));

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    protected function createApiKey(ApiKey $resource): bool
    {
        $existing = $this->dbForPlatform->findOne('keys', [
            Query::equal('resourceType', ['projects']),
            Query::equal('resourceInternalId', [$this->projectInternalId]),
            Query::equal('name', [$resource->getApiKeyName()]),
        ]);

        if ($existing !== false && !$existing->isEmpty()) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'API key already exists');
            return false;
        }

        $createdAt = $this->normalizeDateTime($resource->getCreatedAt());
        $updatedAt = $this->normalizeDateTime($resource->getUpdatedAt(), $createdAt);
        $expire = $resource->getExpire();

        try {
            $this->dbForPlatform->createDocument('keys', new UtopiaDocument([
                '$id' => Id::unique(),
                '$permissions' => $resource->getPermissions(),
                'resourceInternalId' => $this->projectInternalId,
                'resourceId' => $this->projectId,
                'resourceType' => 'projects',
                'name' => $resource->getApiKeyName(),
                'scopes' => $resource->getScopes(),
                'expire' => empty($expire) ? null : $expire,
                'sdks' => $resource->getSdks(),
                'accessedAt' => null,
                'secret' => 'standard_' . \bin2hex(\random_bytes(128)),
                '$createdAt' => $createdAt,
                '$updatedAt' => $updatedAt,
            ]));
        } catch (DuplicateException) {
            $resource->setStatus(Resource::STATUS_SKIPPED, 'API key already exists');
            return false;
        }

        $this->dbForPlatform->purgeCachedDocument('projects', $this->projectId);

        return true;
    }

    private function validateFieldsForIndexes(Index $resource, UtopiaDocument $table, array &$lengths)
    {
        /**
         * @var array<UtopiaDocument> $tableColumns
         */
        $tableColumns = $table->getAttribute('attributes', []);

        $oldColumns = \array_map(
            function ($attr) {
                if ($attr instanceof UtopiaAttribute) {
                    return $attr->toDocument()->getArrayCopy();
                }

                return $attr->getArrayCopy();
            },
            $tableColumns
        );

        $oldColumns[] = [
            'key' => '$id',
            'type' => ColumnType::String->value,
            'status' => 'available',
            'required' => true,
            'array' => false,
            'default' => null,
            'size' => UtopiaDatabase::LENGTH_KEY
        ];

        $oldColumns[] = [
            'key' => '$createdAt',
            'type' => ColumnType::Datetime->value,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];

        $oldColumns[] = [
            'key' => '$updatedAt',
            'type' => ColumnType::Datetime->value,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];

        foreach ($resource->getColumns() as $i => $column) {
            // find attribute metadata in collection document
            $columnIndex = \array_search(
                $column,
                \array_column($oldColumns, 'key')
            );

            if ($columnIndex === false) {
                throw new Exception(
                    resourceName: $resource->getName(),
                    resourceGroup: $resource->getGroup(),
                    resourceId: $resource->getId(),
                    message: 'Column not found in table: ' . $column,
                );
            }

            $columnStatus = $oldColumns[$columnIndex]['status'];
            $columnType = $oldColumns[$columnIndex]['type'];
            $columnArray = $oldColumns[$columnIndex]['array'] ?? false;

            if ($columnType === ColumnType::Relationship->value || $columnType === ColumnType::Relationship) {
                throw new Exception(
                    resourceName: $resource->getName(),
                    resourceGroup: $resource->getGroup(),
                    resourceId: $resource->getId(),
                    message: 'Relationship columns are not supported in indexes',
                );
            }

            // Ensure attribute is available
            if ($columnStatus !== 'available') {
                throw new Exception(
                    resourceName: $resource->getName(),
                    resourceGroup: $resource->getGroup(),
                    resourceId: $resource->getId(),
                    message: 'Column not available: ' . $column,
                );
            }

            // The source's own prefix length, when it recorded one. An index
            // that only fits under the adapter's byte limit because of an
            // explicit prefix (e.g. two large strings capped at 100 and 20)
            // must be recreated with that prefix, or restoring a healthy
            // database fails its own indexes with "Index length is longer
            // than the maximum". Zero means the source recorded no length.
            $sourceLength = $resource->getLengths()[$i] ?? null;
            $lengths[$i] = \is_int($sourceLength) && $sourceLength > 0 ? $sourceLength : null;

            if ($columnArray === true) {
                $lengths[$i] = UtopiaDatabase::MAX_ARRAY_INDEX_LENGTH;
            }
        }
    }
}
