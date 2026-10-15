<?php

namespace Utopia\Migration\Sources;

use Appwrite\AppwriteException;
use Appwrite\Enums\ProjectAuthMethodId;
use Appwrite\Enums\ProjectPolicyId;
use Appwrite\Enums\ProjectProtocolId;
use Appwrite\Models\PolicyPasskey;
use Appwrite\Models\PolicyPasswordPwned;
use Appwrite\Models\PolicyPasswordRotation;
use Appwrite\Models\ProjectList;
use Appwrite\Query;
use Appwrite\Services\Functions;
use Appwrite\Services\Messaging;
use Appwrite\Services\Project;
use Appwrite\Services\Proxy;
use Appwrite\Services\Sites;
use Appwrite\Services\Storage;
use Appwrite\Services\TablesDB;
use Appwrite\Services\Teams;
use Appwrite\Services\Users;
use Appwrite\Services\Webhooks;
use Override;
use Utopia\Client\Destinations;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\DateTime as UtopiaDateTime;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\RelationshipSide;
use Utopia\Migration\Exception;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Auth\AuthMethods;
use Utopia\Migration\Resources\Auth\Hash;
use Utopia\Migration\Resources\Auth\Membership;
use Utopia\Migration\Resources\Auth\OAuth2\OAuth2Provider;
use Utopia\Migration\Resources\Auth\Policies;
use Utopia\Migration\Resources\Auth\Team;
use Utopia\Migration\Resources\Auth\User;
use Utopia\Migration\Resources\Database\Attribute;
use Utopia\Migration\Resources\Database\Collection;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Columns\BigInt;
use Utopia\Migration\Resources\Database\Columns\Boolean;
use Utopia\Migration\Resources\Database\Columns\DateTime;
use Utopia\Migration\Resources\Database\Columns\Decimal;
use Utopia\Migration\Resources\Database\Columns\Email;
use Utopia\Migration\Resources\Database\Columns\Enum;
use Utopia\Migration\Resources\Database\Columns\Integer;
use Utopia\Migration\Resources\Database\Columns\IP;
use Utopia\Migration\Resources\Database\Columns\Line;
use Utopia\Migration\Resources\Database\Columns\LongText;
use Utopia\Migration\Resources\Database\Columns\MediumText;
use Utopia\Migration\Resources\Database\Columns\ObjectType;
use Utopia\Migration\Resources\Database\Columns\Point;
use Utopia\Migration\Resources\Database\Columns\Polygon;
use Utopia\Migration\Resources\Database\Columns\RegularText;
use Utopia\Migration\Resources\Database\Columns\Relationship;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Columns\URL;
use Utopia\Migration\Resources\Database\Columns\Varchar;
use Utopia\Migration\Resources\Database\Columns\Vector;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Document;
use Utopia\Migration\Resources\Database\DocumentsDB;
use Utopia\Migration\Resources\Database\Index;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Resources\Database\VectorsDB;
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
use Utopia\Migration\Resources\Settings\Environment;
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
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Appwrite\Client;
use Utopia\Migration\Sources\Appwrite\Reader;
use Utopia\Migration\Sources\Appwrite\Reader\API as APIReader;
use Utopia\Migration\Sources\Appwrite\Reader\Database as DatabaseReader;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;

class Appwrite extends Source
{
    public const SOURCE_API = 'api';
    public const SOURCE_DATABASE = 'database';

    private const DEFAULT_PAGE_LIMIT = 25;

    protected Client $client;

    private Reader $reader;

    private Users $users;

    private Teams $teams;

    private Storage $storage;

    private Functions $functions;

    private Messaging $messaging;

    private Sites $sites;

    private Project $project;

    private Webhooks $webhooks;

    private Proxy $proxy;

    private ?string $resourceChildId = null;

    /**
     * @var callable(?UtopiaDocument): UtopiaDatabase
     */
    protected mixed $getDatabasesDB;

    /**
     * @throws \Exception
     */
    public function __construct(
        protected string $projectId,
        protected string $endpoint,
        protected string $key,
        callable $getDatabasesDB,
        protected string $source = self::SOURCE_API,
        protected ?UtopiaDatabase $dbForProject = null,
        protected array $queries = [],
    ) {
        $this->client = new Client();
        $this->client
            ->setEndpoint($endpoint)
            ->setProject($projectId)
            ->setKey($key)
            ->setConnectTimeout(static::CONNECT_TIMEOUT)
            ->setTimeout(static::REQUEST_TIMEOUT);

        $this->users = new Users($this->client);
        $this->teams = new Teams($this->client);
        $this->storage = new Storage($this->client);
        $this->functions = new Functions($this->client);
        $this->messaging = new Messaging($this->client);
        $this->sites = new Sites($this->client);
        $this->project = new Project($this->client);
        $this->webhooks = new Webhooks($this->client);
        $this->proxy = new Proxy($this->client);

        $this->headers['x-appwrite-project'] = $this->projectId;
        $this->headers['x-appwrite-key'] = $this->key;

        $this->getDatabasesDB = $getDatabasesDB;

        switch ($this->source) {
            case static::SOURCE_API:
                $this->reader = new APIReader(new TablesDB($this->client));
                break;

            case static::SOURCE_DATABASE:
                if (\is_null($dbForProject)) {
                    throw new \Exception('Database is required for database source', Exception::CODE_VALIDATION);
                }
                $this->reader = new DatabaseReader(
                    $this->dbForProject,
                    $this->getDatabasesDB,
                    $this->projectId
                );
                break;

            default:
                throw new \Exception('Unknown source', Exception::CODE_VALIDATION);
        }
    }

    public static function getName(): string
    {
        return 'Appwrite';
    }

    #[Override]
    public function setResolver(?\Closure $resolver): static
    {
        $this->client->setResolver($resolver);

        return parent::setResolver($resolver);
    }

    #[Override]
    public function setDestinations(Destinations $destinations): static
    {
        $this->client->setDestinations($destinations);

        return parent::setDestinations($destinations);
    }

    #[Override]
    public function run(
        array $resources,
        callable $callback,
        string $rootResourceId = '',
        string $rootResourceType = '',
    ): void {
        $previousResourceChildId = $this->resourceChildId;

        if ($this->resourceChildId === null) {
            $this->resourceChildId = '';

            if (
                $rootResourceId !== ''
                && \array_key_exists($rootResourceType, Resource::DATABASE_TYPE_RESOURCE_MAP)
                && \str_contains($rootResourceId, ':')
            ) {
                [$rootResourceId, $this->resourceChildId] = \explode(':', $rootResourceId, 2);
            }
        }

        try {
            parent::run($resources, $callback, $rootResourceId, $rootResourceType);
        } finally {
            $this->resourceChildId = $previousResourceChildId;
        }
    }

    #[Override]
    public function runWithResourceSelector(
        array $resources,
        callable $callback,
        string $resourceId,
        string $resourceInternalId,
        string $resourceType,
        string $parentResourceId,
        string $parentResourceInternalId,
        string $parentResourceType,
    ): void {
        $previousResourceChildId = $this->resourceChildId;
        $this->resourceChildId = $parentResourceId !== '' ? $resourceId : '';

        try {
            parent::runWithResourceSelector(
                $resources,
                $callback,
                $resourceId,
                $resourceInternalId,
                $resourceType,
                $parentResourceId,
                $parentResourceInternalId,
                $parentResourceType,
            );
        } finally {
            $this->resourceChildId = $previousResourceChildId;
        }
    }

    public function getSourceType(): string
    {
        return $this->source;
    }

    public function supportsDatabaseStatus(): bool
    {
        return $this->getSourceType() === self::SOURCE_DATABASE;
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
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_INDEX,
            Resource::TYPE_ROW,

            // legacy
            Resource::TYPE_DOCUMENT,
            Resource::TYPE_ATTRIBUTE,
            Resource::TYPE_COLLECTION,

            // documentsdb
            Resource::TYPE_DATABASE_DOCUMENTSDB,
            // vectorsdb
            Resource::TYPE_DATABASE_VECTORSDB,

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

            // Backups
            Resource::TYPE_BACKUP_POLICY,

            // Project
            Resource::TYPE_PROJECT_VARIABLE,
            Resource::TYPE_PROJECT_PROTOCOLS,
            Resource::TYPE_PROJECT_LABELS,
            Resource::TYPE_PROJECT_SERVICES,
            Resource::TYPE_PROJECT_EMAIL_TEMPLATE,
            Resource::TYPE_PROJECT_ENVIRONMENT,

            // Domains
            Resource::TYPE_RULE,
        ];
    }

    /**
     * @return int
     */
    public function getDatabasesBatchSize(): int
    {
        return match ($this->source) {
            static::SOURCE_API => 500,
            static::SOURCE_DATABASE => 1000,
        };
    }

    /**
     * @param array<string> $resources
     * @param array<string, array<string>> $resourceIds
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function report(array $resources = [], array $resourceIds = []): array
    {
        $this->validateResourceIds($resourceIds);

        $report = [];

        if (empty($resources)) {
            $resources = $this->getSupportedResources();
        }

        try {
            $this->reportAuth($resources, $report, $resourceIds);
            $this->reportDatabases($resources, $report, $resourceIds);
            $this->reportStorage($resources, $report, $resourceIds);
            $this->reportFunctions($resources, $report, $resourceIds);
            $this->reportMessaging($resources, $report, $resourceIds);
            $this->reportSites($resources, $report, $resourceIds);
            $this->reportIntegrations($resources, $report, $resourceIds);
            $this->reportBackups($resources, $report, $resourceIds);
            $this->reportProjects($resources, $report, $resourceIds);
            $this->reportDomains($resources, $report, $resourceIds);

            $report['version'] = $this->call(
                'GET',
                '/health/version',
                [
                    'x-appwrite-key' => '',
                    'x-appwrite-project' => '',
                ]
            )['version'];
        } catch (\Throwable $e) {
            if ($e->getCode() === 403) {
                throw new \Exception('Missing required scopes.', $e->getCode(), $e);
            } else {
                throw new \Exception($e->getMessage(), $e->getCode(), $e);
            }
        }

        $this->previousReport = $report;

        return $report;
    }

    /**
     * @param array $resources
     * @param array $report
     * @param array<string, array<string>> $resourceIds
     * @throws AppwriteException
     */
    private function reportAuth(array $resources, array &$report, array $resourceIds = []): void
    {
        // check if we need to fetch teams!
        $needTeams = !empty(array_intersect(
            [Resource::TYPE_TEAM, Resource::TYPE_MEMBERSHIP],
            $resources
        ));

        $teams = ['total' => 0, 'teams' => []];

        if (\in_array(Resource::TYPE_USER, $resources)) {
            $userQueries = $this->buildQueries(
                resourceType: Resource::TYPE_USER,
                resourceIds: $resourceIds,
                limit: 1
            );
            $userList = $this->users->list($userQueries);
            $report[Resource::TYPE_USER] = $userList->total;
        }

        if ($needTeams) {
            if (\in_array(Resource::TYPE_MEMBERSHIP, $resources)) {
                $allTeams = [];
                $lastTeam = null;

                while (true) {
                    $params = $this->buildQueries(
                        resourceType: Resource::TYPE_TEAM,
                        resourceIds: $resourceIds,
                        cursor: $lastTeam
                    );
                    $teamList = $this->teams->list($params);

                    $totalTeams = $teamList->total;
                    $currentTeams = $teamList->teams;

                    $allTeams = array_merge($allTeams, $currentTeams);
                    $lastTeam = empty($currentTeams) ? null : end($currentTeams)->id;

                    if (count($currentTeams) < self::DEFAULT_PAGE_LIMIT) {
                        break;
                    }
                }
                $teams = ['total' => $totalTeams, 'teams' => $allTeams];
            } else {
                $params = $this->buildQueries(
                    resourceType: Resource::TYPE_TEAM,
                    resourceIds: $resourceIds,
                    limit: 1
                );
                $teamList = $this->teams->list($params);
                $teams = ['total' => $teamList->total, 'teams' => []];
            }
        }

        if (\in_array(Resource::TYPE_TEAM, $resources)) {
            $report[Resource::TYPE_TEAM] = $teams['total'];
        }

        if (\in_array(Resource::TYPE_MEMBERSHIP, $resources)) {
            $report[Resource::TYPE_MEMBERSHIP] = 0;
            foreach ($teams['teams'] as $team) {
                $report[Resource::TYPE_MEMBERSHIP] += $this->teams->listMemberships(
                    $team->id,
                    [Query::limit(1)]
                )->total;
            }
        }

        if (\in_array(Resource::TYPE_AUTH_METHODS, $resources)) {
            // Singleton — there is exactly one auth-methods config per project.
            $report[Resource::TYPE_AUTH_METHODS] = 1;
        }

        if (\in_array(Resource::TYPE_OAUTH2_PROVIDER, $resources)) {
            $report[Resource::TYPE_OAUTH2_PROVIDER] = \count($this->getOAuth2ProviderResources());
        }

        if (\in_array(Resource::TYPE_POLICIES, $resources)) {
            // Singleton — one policies config per project.
            $report[Resource::TYPE_POLICIES] = 1;
        }
    }

    /**
     * @throws Exception
     * @throws AppwriteException
     */
    private function reportDatabases(array $resources, array &$report, array $resourceIds = []): void
    {
        $this->reader->report($resources, $report, $resourceIds);
    }

    /**
     * @param array $resources
     * @param array $report
     * @param array<string, array<string>> $resourceIds
     * @throws AppwriteException
     */
    private function reportStorage(array $resources, array &$report, array $resourceIds = []): void
    {
        if (\in_array(Resource::TYPE_BUCKET, $resources)) {
            $bucketQueries = $this->buildQueries(
                resourceType: Resource::TYPE_BUCKET,
                resourceIds: $resourceIds,
                limit: 1
            );
            $report[Resource::TYPE_BUCKET] = $this->storage->listBuckets($bucketQueries)->total;
        }

        if (\in_array(Resource::TYPE_FILE, $resources)) {
            $report[Resource::TYPE_FILE] = 0;
            $report['size'] = 0;
            $buckets = [];
            $lastBucket = null;

            while (true) {
                $queries = $this->buildQueries(
                    resourceType: Resource::TYPE_BUCKET,
                    resourceIds: $resourceIds,
                    cursor: $lastBucket,
                );
                $currentBuckets = $this->storage->listBuckets($queries)->buckets;

                $buckets = array_merge($buckets, $currentBuckets);
                $lastBucket = $buckets[count($buckets) - 1]->id ?? null;

                if (count($currentBuckets) < self::DEFAULT_PAGE_LIMIT) {
                    break;
                }
            }

            foreach ($buckets as $bucket) {
                $filesResponse = $this->storage->listFiles(
                    $bucket->id,
                    [Query::limit(1)]
                );

                $report['size'] += $bucket->totalSize ?? 0;
                $report[Resource::TYPE_FILE] += $filesResponse->total;
            }

            $report['size'] = $report['size'] / 1000 / 1000; // MB
        }
    }

    private function reportFunctions(array $resources, array &$report, array $resourceIds = []): void
    {
        $needVarsOrDeployments = (
            \in_array(Resource::TYPE_DEPLOYMENT, $resources) ||
            \in_array(Resource::TYPE_ENVIRONMENT_VARIABLE, $resources)
        );

        $functions = [];
        $totalFunctions = 0;

        if (!$needVarsOrDeployments && \in_array(Resource::TYPE_FUNCTION, $resources)) {
            $functionQueries = $this->buildQueries(
                resourceType: Resource::TYPE_FUNCTION,
                resourceIds: $resourceIds,
                limit: 1
            );
            $report[Resource::TYPE_FUNCTION] = $this->functions->list($functionQueries)->total;
            return;
        }

        if ($needVarsOrDeployments) {
            $lastFunction = null;
            while (true) {
                $params = $this->buildQueries(
                    resourceType: Resource::TYPE_FUNCTION,
                    resourceIds: $resourceIds,
                    cursor: $lastFunction,
                );
                $funcList = $this->functions->list($params);

                $totalFunctions = $funcList->total;
                $currentFunctions = $funcList->functions;
                $functions = array_merge($functions, $currentFunctions);

                $lastFunction = $currentFunctions[count($currentFunctions) - 1]->id ?? null;
                if (count($currentFunctions) < self::DEFAULT_PAGE_LIMIT) {
                    break;
                }
            }
        }

        if (\in_array(Resource::TYPE_FUNCTION, $resources)) {
            $report[Resource::TYPE_FUNCTION] = $totalFunctions;
        }

        if (\in_array(Resource::TYPE_DEPLOYMENT, $resources)) {
            $report[Resource::TYPE_DEPLOYMENT] = 0;
            foreach ($functions as $function) {
                if (!empty($function->deploymentId)) {
                    $report[Resource::TYPE_DEPLOYMENT] += 1;
                }
            }
        }

        if (\in_array(Resource::TYPE_ENVIRONMENT_VARIABLE, $resources)) {
            $report[Resource::TYPE_ENVIRONMENT_VARIABLE] = 0;
            foreach ($functions as $function) {
                // A function list's `vars` also holds a site's variables when the two share an internal ID.
                $report[Resource::TYPE_ENVIRONMENT_VARIABLE] += $this->functions->listVariables($function->id)->total;
            }
        }
    }

    private function reportSites(array $resources, array &$report, array $resourceIds = []): void
    {
        $needVarsOrDeployments = (
            \in_array(Resource::TYPE_SITE_DEPLOYMENT, $resources) ||
            \in_array(Resource::TYPE_SITE_VARIABLE, $resources)
        );

        $sites = [];
        $totalSites = 0;

        if (!$needVarsOrDeployments && \in_array(Resource::TYPE_SITE, $resources)) {
            try {
                $siteQueries = $this->buildQueries(
                    resourceType: Resource::TYPE_SITE,
                    resourceIds: $resourceIds,
                    limit: 1
                );
                $report[Resource::TYPE_SITE] = $this->sites->list($siteQueries)->total;
            } catch (\Throwable $e) {
                if ($e->getCode() >= 500 && $e->getCode() < 600) {
                    $report[Resource::TYPE_SITE] = 0;
                } else {
                    throw $e;
                }
            }
            return;
        }

        if ($needVarsOrDeployments) {
            try {
                $lastSite = null;
                while (true) {
                    $params = $this->buildQueries(
                        resourceType: Resource::TYPE_SITE,
                        resourceIds: $resourceIds,
                        cursor: $lastSite,
                    );
                    $siteList = $this->sites->list($params);

                    $totalSites = $siteList->total;
                    $currentSites = $siteList->sites;
                    $sites = array_merge($sites, $currentSites);

                    if (count($currentSites) === 0 || count($currentSites) < self::DEFAULT_PAGE_LIMIT) {
                        break;
                    }

                    $lastSite = $currentSites[count($currentSites) - 1]->id;
                }
            } catch (\Throwable $e) {
                if ($e->getCode() >= 500 && $e->getCode() < 600) {
                    $sites = [];
                    $totalSites = 0;
                } else {
                    throw $e;
                }
            }
        }

        if (\in_array(Resource::TYPE_SITE, $resources)) {
            $report[Resource::TYPE_SITE] = $totalSites;
        }

        if (\in_array(Resource::TYPE_SITE_DEPLOYMENT, $resources)) {
            $report[Resource::TYPE_SITE_DEPLOYMENT] = 0;
            foreach ($sites as $site) {
                if (!empty($site->deploymentId)) {
                    $report[Resource::TYPE_SITE_DEPLOYMENT] += 1;
                }
            }
        }

        if (\in_array(Resource::TYPE_SITE_VARIABLE, $resources)) {
            $report[Resource::TYPE_SITE_VARIABLE] = 0;
            try {
                foreach ($sites as $site) {
                    $variables = $this->sites->listVariables($site->id);
                    $report[Resource::TYPE_SITE_VARIABLE] += $variables->total ?? 0;
                }
            } catch (\Throwable $e) {
                if ($e->getCode() >= 500 && $e->getCode() < 600) {
                    $report[Resource::TYPE_SITE_VARIABLE] = 0;
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Export Auth Resources
     *
     * @param int $batchSize Max 100
     * @param array<string> $resources
     */
    protected function exportGroupAuth(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_USER, $resources)) {
                $this->exportUsers($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_USER,
                Transfer::GROUP_AUTH,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_TEAM, $resources)) {
                $this->exportTeams($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_TEAM,
                Transfer::GROUP_AUTH,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_MEMBERSHIP, $resources)) {
                $this->exportMemberships($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_MEMBERSHIP,
                Transfer::GROUP_AUTH,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_AUTH_METHODS, $resources)) {
                $this->exportAuthMethods();
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_AUTH_METHODS,
                Transfer::GROUP_AUTH,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_OAUTH2_PROVIDER, $resources)) {
                $this->exportOAuth2Providers();
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_OAUTH2_PROVIDER,
                Transfer::GROUP_AUTH,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_POLICIES, $resources)) {
                $this->exportPolicies();
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_POLICIES,
                Transfer::GROUP_AUTH,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }
    }

    private function exportPolicies(): void
    {
        $passwordHistory = $this->project->getPolicy(ProjectPolicyId::PASSWORDHISTORY());
        $passwordDictionary = $this->project->getPolicy(ProjectPolicyId::PASSWORDDICTIONARY());
        $passwordPersonalData = $this->project->getPolicy(ProjectPolicyId::PASSWORDPERSONALDATA());
        $sessionAlert = $this->project->getPolicy(ProjectPolicyId::SESSIONALERT());
        $sessionDuration = $this->project->getPolicy(ProjectPolicyId::SESSIONDURATION());
        $sessionInvalidation = $this->project->getPolicy(ProjectPolicyId::SESSIONINVALIDATION());
        $sessionLimit = $this->project->getPolicy(ProjectPolicyId::SESSIONLIMIT());
        $userLimit = $this->project->getPolicy(ProjectPolicyId::USERLIMIT());
        $membershipPrivacy = $this->project->getPolicy(ProjectPolicyId::MEMBERSHIPPRIVACY());
        $passwordStrength = $this->getPolicyIfSupported(ProjectPolicyId::PASSWORDSTRENGTH());
        $mfaFactors = $this->getPolicyIfSupported(ProjectPolicyId::MFAFACTORS());
        $denyAliasedEmail = $this->getPolicyIfSupported(ProjectPolicyId::DENYALIASEDEMAIL());
        $denyDisposableEmail = $this->getPolicyIfSupported(ProjectPolicyId::DENYDISPOSABLEEMAIL());
        $denyFreeEmail = $this->getPolicyIfSupported(ProjectPolicyId::DENYFREEEMAIL());
        $denyCorporateEmail = $this->getPolicyIfSupported(ProjectPolicyId::DENYCORPORATEEMAIL());
        $passwordPwned = $this->getPolicyIfSupported(ProjectPolicyId::PASSWORDPWNED());
        $passwordRotation = $this->getPolicyIfSupported(ProjectPolicyId::PASSWORDROTATION());
        $passkey = $this->getPolicyIfSupported(ProjectPolicyId::PASSKEY());

        $policies = new Policies(
            $this->projectId,
            $passwordHistory->total,
            $sessionDuration->duration,
            $sessionLimit->total,
            $userLimit->total,
            $passwordDictionary->enabled,
            $passwordPersonalData->enabled,
            $sessionAlert->enabled,
            $sessionInvalidation->enabled,
            $membershipPrivacy->userId,
            $membershipPrivacy->userEmail,
            $membershipPrivacy->userName,
            $membershipPrivacy->userMFA,
            $membershipPrivacy->userPhone,
            $membershipPrivacy->userAccessedAt,
            $passwordStrength === null ? null : [
                'min' => $passwordStrength->min,
                'uppercase' => $passwordStrength->uppercase,
                'lowercase' => $passwordStrength->lowercase,
                'number' => $passwordStrength->number,
                'symbols' => $passwordStrength->symbols,
            ],
            $mfaFactors === null ? null : [
                'totp' => $mfaFactors->totp,
                'email' => $mfaFactors->email,
                'phone' => $mfaFactors->phone,
                'custom' => $mfaFactors->custom,
            ],
            $denyAliasedEmail?->enabled,
            $denyDisposableEmail?->enabled,
            $denyFreeEmail?->enabled,
            $denyCorporateEmail?->enabled,
            passwordPwned: !$passwordPwned instanceof PolicyPasswordPwned ? null : [
                'enabled' => $passwordPwned->enabled,
                'sessions' => $passwordPwned->sessions,
                'users' => $passwordPwned->users,
            ],
            passwordRotation: !$passwordRotation instanceof PolicyPasswordRotation ? null : [
                'enabled' => $passwordRotation->enabled,
                'maxAge' => $passwordRotation->maxAge,
            ],
            passkeyRpId: $passkey instanceof PolicyPasskey ? $passkey->rpId : null,
        );

        $this->callback([$policies]);
    }

    /**
     * A source older than a policy rejects its ID, so the policy is left to the destination.
     *
     * @throws AppwriteException
     */
    private function getPolicyIfSupported(ProjectPolicyId $policyId): mixed
    {
        try {
            return $this->project->getPolicy($policyId);
        } catch (AppwriteException $e) {
            if ($e->getCode() === 400 || $e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    private function exportAuthMethods(): void
    {
        $project = $this->project->get();

        $byId = [];
        foreach ($project->authMethods as $method) {
            $byId[(string) $method->id] = $method->enabled;
        }

        $authMethods = new AuthMethods(
            $this->projectId,
            $byId[(string) ProjectAuthMethodId::EMAILPASSWORD()] ?? true,
            $byId[(string) ProjectAuthMethodId::MAGICURL()] ?? true,
            $byId[(string) ProjectAuthMethodId::EMAILOTP()] ?? true,
            $byId[(string) ProjectAuthMethodId::ANONYMOUS()] ?? true,
            $byId[(string) ProjectAuthMethodId::INVITES()] ?? true,
            $byId[(string) ProjectAuthMethodId::JWT()] ?? true,
            $byId[(string) ProjectAuthMethodId::PHONE()] ?? true,
            createdAt: $project->createdAt,
            updatedAt: $project->updatedAt,
        );

        $this->callback([$authMethods]);
    }

    private function exportOAuth2Providers(): void
    {
        $resources = $this->getOAuth2ProviderResources(true);

        if (!empty($resources)) {
            $this->callback($resources);
        }
    }

    /**
     * @return array<OAuth2Provider>
     */
    private function getOAuth2ProviderResources(bool $reportUnknownProviders = false): array
    {
        $resources = [];
        foreach ($this->project->listOAuth2Providers()->providers ?? [] as $provider) {
            $key = (string) ($provider['$id'] ?? '');
            if ($key === '') {
                continue;
            }

            $payload = $provider;
            $payload['id'] = $this->projectId . '-' . $key;
            $resource = OAuth2Provider::fromArray($key, $payload);

            if ($resource === null) {
                if ($reportUnknownProviders) {
                    $this->addError(new Exception(
                        Resource::TYPE_OAUTH2_PROVIDER,
                        Transfer::GROUP_AUTH,
                        message: "No migration resource for OAuth2 provider '{$key}'; skipped.",
                        code: Exception::CODE_INTERNAL,
                    ));
                }
                continue;
            }

            if (!$resource->isConfigured()) {
                continue;
            }

            $resources[] = $resource;
        }

        return $resources;
    }

    /**
     * @throws AppwriteException
     */
    private function exportUsers(int $batchSize): void
    {
        $lastDocument = null;

        while (true) {
            $users = [];

            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_USER) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastDocument) {
                $queries[] = Query::cursorAfter($lastDocument);
            }

            $response = $this->users->list($queries);
            if ($response->total == 0) {
                break;
            }

            foreach ($response->users as $user) {
                $users[] = new User(
                    $user->id,
                    empty($user->email) ? null : $user->email,
                    empty($user->name) ? null : $user->name,
                    $user->password ? new Hash($user->password, algorithm: $user->hash) : null,
                    empty($user->phone) ? null : $user->phone,
                    $user->labels ?? [],
                    '',
                    $user->emailVerification ?? false,
                    $user->phoneVerification ?? false,
                    !$user->status,
                    $user->prefs->data ?? [],
                    \array_map(fn ($target) => $target->toArray(), $user->targets ?? []),
                );

                $lastDocument = $user->id;
            }

            $this->callback($users);

            if (count($users) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportTeams(int $batchSize): void
    {
        $this->teams = new Teams($this->client);
        $lastDocument = null;

        while (true) {
            $teams = [];

            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_TEAM) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastDocument) {
                $queries[] = Query::cursorAfter($lastDocument);
            }

            $response = $this->teams->list($queries);
            if ($response->total == 0) {
                break;
            }

            foreach ($response->teams as $team) {
                $teams[] = new Team(
                    $team->id,
                    $team->name,
                    $team->prefs->data,
                );

                $lastDocument = $team->id;
            }

            $this->callback($teams);

            if (count($teams) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @throws AppwriteException
     * @throws \Exception
     */
    private function exportMemberships(int $batchSize): void
    {
        $cacheTeams = $this->cache->get(Team::getName());

        /** @var array<string, User> $cacheUsers */
        $cacheUsers = [];

        foreach ($this->cache->get(User::getName()) as $cacheUser) {
            $cacheUsers[$cacheUser->getId()] = $cacheUser;
        }

        foreach ($cacheTeams as $team) {
            /** @var Team $team */
            $lastDocument = null;

            while (true) {
                $memberships = [];

                $queries = [Query::limit($batchSize)];

                if ($lastDocument) {
                    $queries[] = Query::cursorAfter($lastDocument);
                }

                $response = $this->teams->listMemberships($team->getId(), $queries);

                if ($response->total == 0) {
                    break;
                }

                foreach ($response->memberships as $membership) {
                    $user = $cacheUsers[$membership->userId] ?? null;
                    if ($user === null) {
                        throw new \Exception('User not found', Exception::CODE_NOT_FOUND);
                    }

                    $memberships[] = new Membership(
                        $membership->id,
                        $team,
                        $user,
                        $membership->roles,
                        $membership->confirm
                    );

                    $lastDocument = $membership->id;
                }

                $this->callback($memberships);

                if (count($memberships) < $batchSize) {
                    break;
                }
            }
        }
    }

    protected function exportGroupDatabases(int $batchSize, array $resources): void
    {
        $handleExportEntityScopedResources = function (string $resourceKey, callable $callback) use ($resources) {
            foreach (Resource::ENTITY_TYPE_RESOURCE_MAP as $entityKey => $entityResource) {
                try {
                    if (\in_array($entityResource[$resourceKey], $resources)) {
                        $callback($entityKey, $entityResource);
                    }
                } catch (\Throwable $e) {
                    $this->addError(
                        new Exception(
                            $entityResource[$resourceKey],
                            Transfer::GROUP_DATABASES,
                            message: $e->getMessage(),
                            code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                            previous: $e
                        )
                    );

                    return false;
                }
            }
            return true;
        };

        try {
            if (Resource::isSupported(array_keys(Resource::DATABASE_TYPE_RESOURCE_MAP), $resources)) {
                $this->exportDatabases($batchSize, $resources);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_DATABASE,
                    Transfer::GROUP_DATABASES,
                    message: $e->getMessage(),
                    code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                    previous: $e
                )
            );

            return;
        }

        foreach (Resource::DATABASE_TYPE_RESOURCE_MAP as $databaseKey => $databaseResource) {
            try {
                if (\in_array($databaseResource['entity'], $resources)) {
                    $this->exportEntities($databaseKey, $batchSize);
                }
            } catch (\Throwable $e) {
                $this->addError(
                    new Exception(
                        $databaseResource['entity'],
                        Transfer::GROUP_DATABASES,
                        message: $e->getMessage(),
                        code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                        previous: $e
                    )
                );

                return;
            }
        }

        // field
        if (!$handleExportEntityScopedResources('field', fn ($entityKey) => $this->exportFields($entityKey, $batchSize))) {
            return;
        }

        // index
        if (!$handleExportEntityScopedResources('index', fn ($entityKey) => $this->exportIndexes($entityKey, $batchSize))) {
            return;
        }

        // record
        if (!$handleExportEntityScopedResources('record', fn ($entityKey, $entityResource) => $this->exportRecords($entityKey, $entityResource['field'], $batchSize))) {
            return;
        }
    }

    /**
     * @param int $batchSize
     * @param array $resources
     * @throws Exception
     */
    private function exportDatabases(int $batchSize, array $resources = []): void
    {
        $lastDatabase = null;

        while (true) {
            $queries = [$this->reader->queryLimit($batchSize)];

            if ($this->rootResourceId !== '' && \array_key_exists($this->rootResourceType, Resource::DATABASE_TYPE_RESOURCE_MAP)) {
                $queries[] = $this->reader->queryEqual('$id', [$this->rootResourceId]);
                $queries[] = $this->reader->queryLimit(1);
            }

            $databases = [];

            if ($lastDatabase) {
                $queries[] = $this->reader->queryCursorAfter($lastDatabase);
            }

            $response = $this->reader->listDatabases($queries);

            foreach ($response as $database) {
                $originalType = $database['type'] ?? Resource::TYPE_DATABASE_LEGACY;

                $databaseFamilyType = $originalType;
                if (\in_array($databaseFamilyType, [Resource::TYPE_DATABASE_LEGACY, Resource::TYPE_DATABASE_TABLESDB], true)) {
                    $databaseFamilyType = Resource::TYPE_DATABASE;
                }

                if (Resource::isSupported($databaseFamilyType, $resources)) {
                    $newDatabase = self::getDatabase($databaseFamilyType, [
                        'id' => $database['$id'],
                        'name' => $database['name'],
                        'createdAt' => $database['$createdAt'],
                        'updatedAt' => $database['$updatedAt'],
                        'type' => $originalType,
                        'database' => $database['database'],
                        'enabled' => $database['enabled'] ?? true,
                    ]);
                    $databases[] = $newDatabase;
                }
            }

            if (empty($databases)) {
                break;
            }

            $lastDatabase = $databases[\count($databases) - 1];

            $this->callback($databases);

            if (\count($databases) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @param string $databaseName
     * @param int $batchSize
     * @throws Exception
     */
    private function exportEntities(string $databaseName, int $batchSize): void
    {
        $databases = $this->cache->get($databaseName);
        foreach ($databases as $database) {
            /** @var Database $database */
            $lastTable = null;

            while (true) {
                $queries = [$this->reader->queryLimit($batchSize)];
                $tables = [];

                // Filter to a specific table or collection when its database root has a child set,
                // or when the root itself is a table.
                if (
                    $this->rootResourceId !== '' &&
                    $this->resourceChildId !== '' &&
                    $this->rootResourceType === $databaseName
                ) {
                    $queries[] = $this->reader->queryEqual('$id', [$this->resourceChildId]);
                    $queries[] = $this->reader->queryLimit(1);
                } elseif (
                    $this->rootResourceId !== '' &&
                    $this->rootResourceType === Resource::TYPE_TABLE
                ) {
                    $queries[] = $this->reader->queryEqual('$id', [$this->rootResourceId]);
                    $queries[] = $this->reader->queryLimit(1);
                } elseif ($lastTable) {
                    $queries[] = $this->reader->queryCursorAfter($lastTable);
                }

                $response = $this->reader->listTables($database, $queries);
                foreach ($response as $table) {
                    $rowSecurity = $table['rowSecurity'] ?? $table['documentSecurity'] ?? false;
                    $newTable = self::getEntity($databaseName, [
                        'id' => $table['$id'],
                        'name' => $table['name'],
                        'rowSecurity' => $rowSecurity,
                        'documentSecurity' => $rowSecurity,
                        'permissions' => $table['$permissions'],
                        'createdAt' => $table['$createdAt'],
                        'updatedAt' => $table['$updatedAt'],
                        'enabled' => $table['enabled'] ?? true,
                        'dimension' => $table['dimension'] ?? null,
                        'database' => [
                            'id' => $database->getId(),
                            'name' => $database->getDatabaseName(),
                            'type' => $database->getType(),
                            'database' => $database->getDatabase(),
                        ]
                    ]);

                    $tables[] = $newTable;
                }

                if (empty($tables)) {
                    break;
                }

                $this->callback($tables);

                $lastTable = $tables[\count($tables) - 1];

                if (\count($tables) < $batchSize) {
                    break;
                }
            }
        }
    }

    /**
     * @param string $entityType
     * @param int $batchSize
     * @throws Exception
     */
    private function exportFields(string $entityType, int $batchSize): void
    {
        $entities = $this->cache->get($entityType);
        // Transfer Indexes

        /** @var array<Table|Collection> $table */
        foreach ($entities as $table) {
            $lastColumn = null;

            while (true) {
                $queries = [$this->reader->queryLimit($batchSize)];
                $columns = [];

                if ($lastColumn) {
                    $queries[] = $this->reader->queryCursorAfter($lastColumn);
                }

                $response = $this->reader->listColumns($table, $queries);

                foreach ($response as $column) {
                    if (
                        $column['type'] === ColumnType::Relationship->value
                        && $column['side'] === RelationshipSide::Child->value
                    ) {
                        continue;
                    }

                    /** @var Table $table */
                    $col = match ($table->getDatabase()->getType()) {
                        Resource::TYPE_DATABASE_VECTORSDB => self::getColumn($table, $column)->getAttribute(),
                        default => self::getColumn($table, $column),
                    };

                    $columns[] = $col;
                }

                if (empty($columns)) {
                    break;
                }

                $this->callback($columns);

                $lastColumn = $columns[count($columns) - 1];

                if (count($columns) < $batchSize) {
                    break;
                }
            }
        }
    }

    /**
     * @param string $entityType
     * @param int $batchSize
     * @throws Exception
     */
    private function exportIndexes(string $entityType, int $batchSize): void
    {
        $entities = $this->cache->get($entityType);
        // Transfer Indexes
        foreach ($entities as $table) {
            /** @var Table $table */
            $lastIndex = null;

            while (true) {
                $queries = [$this->reader->queryLimit($batchSize)];
                $indexes = [];

                if ($lastIndex) {
                    $queries[] = $this->reader->queryCursorAfter($lastIndex);
                }

                $response = $this->reader->listIndexes($table, $queries);

                foreach ($response as $index) {
                    $indexes[] = new Index(
                        $index['$id'],
                        $index['key'],
                        $table,
                        $index['type'],
                        $index['columns'] ?? $index['attributes'],
                        $index['lengths'] ?? [],
                        $index['orders'] ?? [],
                        $index['$createdAt'] = empty($index['$createdAt']) ? UtopiaDateTime::now() : $index['$createdAt'],
                        $index['$updatedAt'] = empty($index['$updatedAt']) ? UtopiaDateTime::now() : $index['$updatedAt'],
                    );
                }

                if (empty($indexes)) {
                    break;
                }

                $this->callback($indexes);

                $lastIndex = $indexes[count($indexes) - 1];

                if (count($indexes) < $batchSize) {
                    break;
                }
            }
        }
    }

    /**
     * @param string $entityName
     * @param string $fieldName
     * @param int $batchSize
     * @throws Exception
     */
    private function exportRecords(string $entityName, string $fieldName, int $batchSize): void
    {
        $entities = $this->cache->get($entityName);

        foreach ($entities as $table) {
            /** @var Table $table */
            $lastRow = null;
            $iterationCount = 0;

            while (true) {
                $iterationCount++;

                $queries = [
                    $this->reader->queryLimit($batchSize),
                    ...$this->queries,
                ];

                $rows = [];

                if ($lastRow) {
                    $queries[] = $this->reader->queryCursorAfter($lastRow);
                }

                $selects = ['*', '$id', '$permissions', '$updatedAt', '$createdAt']; // We want relations flat!
                $manyToMany = [];

                if ($this->reader->getSupportForAttributes()) {
                    $attributes = $this->cache->get($fieldName);

                    foreach ($attributes as $attribute) {
                        /** @var Relationship $attribute */
                        if (
                            $attribute->getTable()->getId() === $table->getId() &&
                            $attribute->getType() === Column::TYPE_RELATIONSHIP &&
                            $attribute->getSide() === 'parent' &&
                            $attribute->getRelationType() == 'manyToMany'
                        ) {
                            $manyToMany[] = $attribute->getKey();
                        }
                    }
                }

                $queries[] = $this->reader->querySelect($selects);

                $response = $this->reader->listRows($table, $queries);

                foreach ($response as $row) {
                    // HACK: Handle many to many (only for schema-based databases)
                    if (!empty($manyToMany)) {
                        $stack = ['$id']; // Adding $id because we can't select only relations
                        foreach ($manyToMany as $relation) {
                            $stack[] = $relation . '.$id';
                        }

                        $rowItem = $this->reader->getRow(
                            $table,
                            $row['$id'],
                            [$this->reader->querySelect($stack)]
                        );

                        foreach ($manyToMany as $key) {
                            $row[$key] = [];
                            if (isset($rowItem[$key]) && is_array($rowItem[$key])) {
                                foreach ($rowItem[$key] as $relatedRowItem) {
                                    if (is_array($relatedRowItem) && isset($relatedRowItem['$id'])) {
                                        $row[$key][] = $relatedRowItem['$id'];
                                    }
                                }
                            }
                        }
                    }

                    $id = $row['$id'];
                    $permissions = $row['$permissions'];

                    unset($row['$id']);
                    unset($row['$permissions']);
                    unset($row['$databaseId']);
                    unset($row['$sequence']);
                    unset($row['$collection']);
                    unset($row['$tableId']);
                    unset($row['$table']);

                    $record = self::getRecord($table->getDatabase()->getType(), [
                        'id' => $id,
                        'table' => [
                            'id' => $table->getId(),
                            'name' => $table->getTableName(),
                            'rowSecurity' => $table->getRowSecurity(),
                            'permissions' => $table->getPermissions(),
                            'database' => [
                                'id' => $table->getDatabase()->getId(),
                                'name' => $table->getDatabase()->getDatabaseName(),
                                'type' => $table->getDatabase()->getType(),
                                'database' => $table->getDatabase()->getDatabase(),
                            ]
                        ],
                        'data' => $row,
                        'permissions' => $permissions
                    ]);

                    $rows[] = $record;
                    $lastRow = $record;
                }

                $this->callback($rows);

                if (count($response) < $batchSize) {
                    break;
                }
            }
        }
    }

    protected function exportGroupStorage(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_BUCKET, $resources)) {
                $this->exportBuckets($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_BUCKET,
                    Transfer::GROUP_STORAGE,
                    message: $e->getMessage(),
                    code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                    previous: $e
                )
            );
        }

        try {
            if (\in_array(Resource::TYPE_FILE, $resources)) {
                $this->exportFiles($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_FILE,
                    Transfer::GROUP_STORAGE,
                    message: $e->getMessage(),
                    code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                    previous: $e
                )
            );
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportBuckets(int $batchSize): void
    {
        $queries = [];

        if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_BUCKET) {
            $queries[] = Query::equal('$id', $this->rootResourceId);
            $queries[] = Query::limit(1);
        }

        $buckets = $this->storage->listBuckets($queries);

        $convertedBuckets = [];

        foreach ($buckets->buckets as $bucket) {
            $convertedBuckets[] = new Bucket(
                $bucket->id,
                $bucket->name,
                $bucket->permissions,
                $bucket->fileSecurity,
                $bucket->enabled,
                $bucket->maximumFileSize,
                $bucket->allowedFileExtensions,
                $bucket->compression,
                $bucket->encryption,
                $bucket->antivirus,
                false,
                $bucket->transformations ?? false,
            );
        }

        if (empty($convertedBuckets)) {
            return;
        }

        $this->callback($convertedBuckets);
    }

    /**
     * @throws AppwriteException
     */
    private function exportFiles(int $batchSize): void
    {
        $buckets = $this->cache->get(Bucket::getName());

        foreach ($buckets as $bucket) {
            /** @var Bucket $bucket */
            $lastDocument = null;

            while (true) {
                $queries = [Query::limit($batchSize)];

                if ($lastDocument) {
                    $queries[] = Query::cursorAfter($lastDocument);
                }

                $response = $this->storage->listFiles(
                    $bucket->getId(),
                    $queries
                );

                foreach ($response->files as $file) {
                    try {
                        $this->exportFileData(new File(
                            $file->id,
                            $bucket,
                            $file->name,
                            $file->signature,
                            $file->mimeType,
                            $file->permissions,
                            $file->sizeOriginal,
                            folder: $file->folder,
                        ));
                    } catch (\Throwable $e) {
                        $this->addError(new Exception(
                            resourceName: Resource::TYPE_FILE,
                            resourceGroup: Transfer::GROUP_STORAGE,
                            resourceId: $file->id,
                            message: $e->getMessage(),
                            code: $e->getCode()
                        ));
                    }

                    $lastDocument = $file->id;
                }

                if (count($response->files) < $batchSize) {
                    break;
                }
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function exportFileData(File $file): void
    {
        // Set the chunk size (5MB)
        $start = 0;
        $end = Transfer::STORAGE_MAX_CHUNK_SIZE - 1;

        // Get the file size
        $fileSize = $file->getSize();

        if ($end >= $fileSize) {
            $end = $fileSize - 1;
        }

        // Loop until the entire file is downloaded
        while ($start < $fileSize) {
            $chunkData = $this->call(
                'GET',
                "/storage/buckets/{$file->getBucket()->getId()}/files/{$file->getId()}/download",
                ['range' => "bytes=$start-$end"]
            );

            // Send the chunk to the callback function
            $file
                ->setData($chunkData)
                ->setStart($start)
                ->setEnd($end);

            $this->callback([$file]);

            // Update the range
            $start += Transfer::STORAGE_MAX_CHUNK_SIZE;
            $end += Transfer::STORAGE_MAX_CHUNK_SIZE;

            if ($end >= $fileSize) {
                $end = $fileSize - 1;
            }
        }
    }

    protected function exportGroupFunctions(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_FUNCTION, $resources)) {
                $this->exportFunctions($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_FUNCTION,
                Transfer::GROUP_FUNCTIONS,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        try {
            $exportOnlyActive = !\in_array(Resource::TYPE_DEPLOYMENT, $resources);
            $this->exportDeployments($batchSize, $exportOnlyActive);
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_DEPLOYMENT,
                Transfer::GROUP_FUNCTIONS,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }
    }

    protected function exportGroupSites(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_SITE, $resources)) {
                $this->exportSites($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_SITE,
                Transfer::GROUP_SITES,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        try {
            $exportOnlyActive = !\in_array(Resource::TYPE_SITE_DEPLOYMENT, $resources);
            $this->exportSiteDeployments($batchSize, $exportOnlyActive);
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_SITE_DEPLOYMENT,
                Transfer::GROUP_SITES,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }
    }

    protected function exportGroupBackups(int $batchSize, array $resources): void
    {
        // No-op: backup policies are Cloud-only
    }

    protected function reportBackups(array $resources, array &$report, array $resourceIds = []): void
    {
        if (\in_array(Resource::TYPE_BACKUP_POLICY, $resources)) {
            $report[Resource::TYPE_BACKUP_POLICY] = 0;
        }
    }

    private function reportDomains(array $resources, array &$report, array $resourceIds = []): void
    {
        if (\in_array(Resource::TYPE_RULE, $resources)) {
            try {
                $report[Resource::TYPE_RULE] = $this->proxy->listRules([Query::limit(1)])->total;
            } catch (\Throwable $e) {
                if ($e->getCode() >= 500 && $e->getCode() < 600) {
                    $report[Resource::TYPE_RULE] = 0;
                } else {
                    throw $e;
                }
            }
        }
    }

    private function reportProjects(array $resources, array &$report, array $resourceIds = []): void
    {
        if (\in_array(Resource::TYPE_PROJECT_VARIABLE, $resources)) {
            $variableQueries = $this->buildQueries(
                resourceType: Resource::TYPE_PROJECT_VARIABLE,
                resourceIds: $resourceIds,
                limit: 1
            );
            try {
                $report[Resource::TYPE_PROJECT_VARIABLE] = $this->project->listVariables($variableQueries)->total;
            } catch (\Throwable $e) {
                if ($e->getCode() >= 500 && $e->getCode() < 600) {
                    $report[Resource::TYPE_PROJECT_VARIABLE] = 0;
                } else {
                    throw $e;
                }
            }
        }

        if (\in_array(Resource::TYPE_PROJECT_PROTOCOLS, $resources)) {
            // Singleton — there is exactly one protocols config per project.
            $report[Resource::TYPE_PROJECT_PROTOCOLS] = 1;
        }

        if (\in_array(Resource::TYPE_PROJECT_LABELS, $resources)) {
            // Singleton — one labels array per project.
            $report[Resource::TYPE_PROJECT_LABELS] = 1;
        }

        if (\in_array(Resource::TYPE_PROJECT_SERVICES, $resources)) {
            // Singleton — one services config per project.
            $report[Resource::TYPE_PROJECT_SERVICES] = 1;
        }

        if (\in_array(Resource::TYPE_PROJECT_EMAIL_TEMPLATE, $resources)) {
            try {
                // total:true returns the real count without fetching every row.
                $report[Resource::TYPE_PROJECT_EMAIL_TEMPLATE] = $this->project->listEmailTemplates([Query::limit(1)], total: true)->total;
            } catch (\Throwable) {
                $report[Resource::TYPE_PROJECT_EMAIL_TEMPLATE] = 0;
            }
        }

        if (\in_array(Resource::TYPE_PROJECT_ENVIRONMENT, $resources)) {
            $environments = $this->listEnvironmentsIfSupported([Query::limit(1)]);
            $report[Resource::TYPE_PROJECT_ENVIRONMENT] = $environments === null ? 0 : $environments->total;
        }
    }

    /**
     * @param int $batchSize
     * @param array<string> $resources
     */
    protected function exportGroupProjects(int $batchSize, array $resources): void
    {
        if (\in_array(Resource::TYPE_PROJECT_VARIABLE, $resources)) {
            try {
                $this->exportProjectVariables($batchSize);
            } catch (\Throwable $e) {
                $this->addError(new Exception(
                    Resource::TYPE_PROJECT_VARIABLE,
                    Transfer::GROUP_PROJECTS,
                    message: $e->getMessage(),
                    code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                    previous: $e
                ));
            }
        }

        try {
            if (\in_array(Resource::TYPE_PROJECT_PROTOCOLS, $resources)) {
                $this->exportProtocols();
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_PROJECT_PROTOCOLS,
                Transfer::GROUP_PROJECTS,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_PROJECT_LABELS, $resources)) {
                $this->exportLabels();
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_PROJECT_LABELS,
                Transfer::GROUP_PROJECTS,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_PROJECT_SERVICES, $resources)) {
                $this->exportServices();
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_PROJECT_SERVICES,
                Transfer::GROUP_PROJECTS,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }

        if (\in_array(Resource::TYPE_PROJECT_EMAIL_TEMPLATE, $resources)) {
            try {
                $this->exportEmailTemplates($batchSize);
            } catch (\Throwable $e) {
                $this->addError(new Exception(
                    Resource::TYPE_PROJECT_EMAIL_TEMPLATE,
                    Transfer::GROUP_PROJECTS,
                    message: $e->getMessage(),
                    code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                    previous: $e
                ));
            }
        }

        if (\in_array(Resource::TYPE_PROJECT_ENVIRONMENT, $resources)) {
            try {
                $this->exportEnvironments($batchSize);
            } catch (\Throwable $e) {
                $this->addError(new Exception(
                    Resource::TYPE_PROJECT_ENVIRONMENT,
                    Transfer::GROUP_PROJECTS,
                    message: $e->getMessage(),
                    code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                    previous: $e
                ));
            }
        }
    }

    private function exportEnvironments(int $batchSize): void
    {
        $lastId = null;

        while (true) {
            $queries = [Query::limit($batchSize)];

            if ($lastId !== null) {
                $queries[] = Query::cursorAfter($lastId);
            }

            $response = $this->listEnvironmentsIfSupported($queries);
            if ($response === null || $response->projects === []) {
                break;
            }

            $environments = [];

            foreach ($response->projects as $environment) {
                $environments[] = new Environment(
                    $environment->id,
                    $environment->name,
                    createdAt: $environment->createdAt,
                    updatedAt: $environment->updatedAt,
                );

                $lastId = $environment->id;
            }

            $this->callback($environments);

            if (\count($response->projects) < $batchSize) {
                break;
            }
        }
    }

    /**
     * Environments are a Cloud feature; a source without them rejects the route.
     *
     * @param array<string> $queries
     * @throws AppwriteException
     */
    private function listEnvironmentsIfSupported(array $queries): ?ProjectList
    {
        try {
            return $this->project->listEnvironments($queries);
        } catch (AppwriteException $e) {
            if ($e->getCode() === 400 || $e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    private function exportServices(): void
    {
        $project = $this->project->get();

        $byId = [];
        foreach ($project->services as $service) {
            $byId[(string) $service->id] = (bool) $service->enabled;
        }

        $services = new ServicesResource(
            $this->projectId,
            $byId,
            createdAt: $project->createdAt,
            updatedAt: $project->updatedAt,
        );

        $this->callback([$services]);
    }

    private function exportLabels(): void
    {
        $project = $this->project->get();

        $labels = new Labels(
            $this->projectId,
            $project->labels,
            createdAt: $project->createdAt,
            updatedAt: $project->updatedAt,
        );

        $this->callback([$labels]);
    }

    private function exportProtocols(): void
    {
        $project = $this->project->get();

        $byId = [];
        foreach ($project->protocols as $protocol) {
            $byId[(string) $protocol->id] = $protocol->enabled;
        }

        $protocols = new Protocols(
            $this->projectId,
            $byId[(string) ProjectProtocolId::REST()] ?? true,
            $byId[(string) ProjectProtocolId::GRAPHQL()] ?? true,
            $byId[(string) ProjectProtocolId::WEBSOCKET()] ?? true,
            createdAt: $project->createdAt,
            updatedAt: $project->updatedAt,
        );

        $this->callback([$protocols]);
    }

    private function exportSMTP(): void
    {
        $project = $this->project->get();

        $smtp = new SMTP(
            $this->projectId,
            $project->smtpEnabled,
            $project->smtpSenderName,
            $project->smtpSenderEmail,
            $project->smtpReplyToName,
            $project->smtpReplyToEmail,
            $project->smtpHost,
            $project->smtpPort,
            $project->smtpUsername,
            $project->smtpSecure,
            createdAt: $project->createdAt,
            updatedAt: $project->updatedAt,
        );

        $this->callback([$smtp]);
    }

    protected function exportGroupDomains(int $batchSize, array $resources): void
    {
        if (\in_array(Resource::TYPE_RULE, $resources)) {
            try {
                $this->exportRules($batchSize);
            } catch (\Throwable $e) {
                $this->addError(new Exception(
                    Resource::TYPE_RULE,
                    Transfer::GROUP_DOMAINS,
                    message: $e->getMessage(),
                    code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                    previous: $e
                ));
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportRules(int $batchSize): void
    {
        $lastId = null;

        while (true) {
            $queries = [Query::limit($batchSize)];

            if ($lastId !== null) {
                $queries[] = Query::cursorAfter($lastId);
            }

            $response = $this->proxy->listRules($queries);
            if ($response->total === 0) {
                break;
            }

            $rules = [];

            foreach ($response->rules as $rule) {
                $rules[] = new Rule(
                    $rule->id,
                    $rule->domain,
                    $rule->type,
                    $rule->trigger,
                    $rule->redirectUrl,
                    $rule->redirectStatusCode,
                    $rule->deploymentResourceType ? (string) $rule->deploymentResourceType : '',
                    $rule->deploymentResourceId,
                    $rule->deploymentVcsProviderBranch,
                    createdAt: $rule->createdAt,
                    updatedAt: $rule->updatedAt,
                );

                $lastId = $rule->id;
            }

            $this->callback($rules);

            if (count($response->rules) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportEmailTemplates(int $batchSize): void
    {
        // Offset pagination: templates come from a project attribute map, not a
        // collection, so cursor pagination isn't available here.
        $offset = 0;

        while (true) {
            $response = $this->project->listEmailTemplates([
                Query::limit($batchSize),
                Query::offset($offset),
            ]);

            if (\count($response->templates) === 0) {
                break;
            }

            $templates = [];

            foreach ($response->templates as $template) {
                $id = 'email.' . $template->templateId . '-' . $template->locale;

                $templates[] = new EmailTemplate(
                    $id,
                    $template->templateId,
                    $template->locale,
                    $template->subject,
                    $template->message,
                    $template->senderName,
                    $template->senderEmail,
                    $template->replyToEmail,
                    $template->replyToName,
                );
            }

            $this->callback($templates);

            if (\count($response->templates) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportProjectVariables(int $batchSize): void
    {
        $lastId = null;

        while (true) {
            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_PROJECT_VARIABLE) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastId !== null) {
                $queries[] = Query::cursorAfter($lastId);
            }

            $response = $this->project->listVariables($queries);
            if ($response->total === 0) {
                break;
            }

            $variables = [];

            foreach ($response->variables as $variable) {
                $variables[] = new ProjectVariable(
                    $variable->id,
                    $variable->key,
                    $variable->value,
                    $variable->secret,
                    createdAt: $variable->createdAt,
                    updatedAt: $variable->updatedAt,
                );

                $lastId = $variable->id;
            }

            $this->callback($variables);

            if (\count($response->variables) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportWebhooks(int $batchSize): void
    {
        $lastId = null;

        while (true) {
            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_WEBHOOK) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastId !== null) {
                $queries[] = Query::cursorAfter($lastId);
            }

            $response = $this->webhooks->list($queries);
            if ($response->total === 0) {
                break;
            }

            $webhooks = [];

            foreach ($response->webhooks as $webhook) {
                $webhooks[] = new Webhook(
                    $webhook->id,
                    $webhook->name,
                    $webhook->url,
                    $webhook->events,
                    $webhook->tls,
                    $webhook->authUsername,
                    $webhook->authPassword,
                    $webhook->enabled,
                    createdAt: $webhook->createdAt,
                    updatedAt: $webhook->updatedAt,
                );

                $lastId = $webhook->id;
            }

            $this->callback($webhooks);

            if (\count($response->webhooks) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportFunctions(int $batchSize): void
    {
        $this->functions = new Functions($this->client);
        /**
         * @var Func|null $lastFunction
         */
        $lastFunction = null;
        while (true) {
            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_FUNCTION) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastFunction) {
                $queries[] = Query::cursorAfter($lastFunction->getId());
            }

            $response = $this->functions->list($queries);


            if ($response->total === 0) {
                return;
            }

            $functions = [];
            $convertedResources = [];

            foreach ($response->functions as $function) {
                $convertedFunc = new Func(
                    $function->id,
                    $function->name,
                    $function->runtime,
                    $function->execute,
                    $function->enabled,
                    $function->events,
                    $function->schedule,
                    $function->timeout,
                    $function->deploymentId ?? '',
                    $function->entrypoint,
                    $function->commands ?? '',
                    $function->logging ?? true,
                    $function->scopes ?? [],
                    $function->runtimeSpecification ?: '',
                    $function->buildSpecification ?: '',
                    $function->interval ?? 0,
                    $function->deploymentRetention,
                );
                $functions[] = $convertedFunc;

                $convertedResources[] = $convertedFunc;

                foreach ($this->functions->listVariables($function->id)->variables as $var) {
                    $convertedResources[] = new EnvVar(
                        $var->id,
                        $convertedFunc,
                        $var->key,
                        $var->value,
                        $var->secret,
                    );
                }
            }

            $lastFunction = $functions[count($functions) - 1];

            $this->callback($convertedResources);
            if (count($functions) < $batchSize) {
                return;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportDeployments(int $batchSize, bool $exportOnlyActive = false): void
    {
        $this->functions = new Functions($this->client);
        $functions = $this->cache->get(Func::getName());

        foreach ($functions as $func) {
            /** @var Func $func */
            $lastDocument = null;

            if ($exportOnlyActive) {
                $activeDeploymentId = $func->getActiveDeployment();
                if (empty($activeDeploymentId)) {
                    continue; // active-only mode: nothing to export for this function
                }

                $deployment = $this->functions->getDeployment($func->getId(), $activeDeploymentId);

                try {
                    $this->exportDeploymentData($func, $deployment);
                } catch (\Throwable $e) {
                    $func->setStatus(Resource::STATUS_ERROR, $e->getMessage());
                }

                continue;
            }

            while (true) {
                $queries = [Query::limit($batchSize)];

                if ($lastDocument) {
                    $queries[] = Query::cursorAfter($lastDocument);
                }

                $response = $this->functions->listDeployments(
                    $func->getId(),
                    $queries
                );

                foreach ($response->deployments as $deployment) {
                    try {
                        $this->exportDeploymentData($func, $deployment);
                    } catch (\Throwable $e) {
                        $func->setStatus(Resource::STATUS_ERROR, $e->getMessage());
                    }

                    $lastDocument = $deployment->id;
                }

                if (count($response->deployments) < $batchSize) {
                    break;
                }
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function exportDeploymentData(Func $func, \Appwrite\Models\Deployment $deployment): void
    {
        // Set the chunk size (5MB)
        $start = 0;
        $end = Transfer::STORAGE_MAX_CHUNK_SIZE - 1;

        // Get the file size
        $responseHeaders = [];

        $this->call(
            'HEAD',
            "/functions/{$func->getId()}/deployments/{$deployment->id}/download",
            [],
            [],
            $responseHeaders
        );

        // content-length header missing, file is less than max buffer size
        if (!array_key_exists('content-length', $responseHeaders)) {
            $file = $this->call(
                'GET',
                "/functions/{$func->getId()}/deployments/{$deployment->id}/download",
                [],
                [],
                $responseHeaders
            );

            $size = strlen($file);

            if ($end > $size) {
                $end = $size - 1;
            }

            $deployment = new Deployment(
                $deployment->id,
                $func,
                $size,
                $deployment->entrypoint,
                $start,
                $end,
                $file,
                $deployment->activate
            );
            $deployment->setSequence($deployment->getId());

            $this->callback([$deployment]);

            return;
        }

        $fileSize = (int) $responseHeaders['content-length'];

        if ($end >= $fileSize) {
            $end = $fileSize - 1;
        }

        $deployment = new Deployment(
            $deployment->id,
            $func,
            $fileSize,
            $deployment->entrypoint,
            $start,
            $end,
            '',
            $deployment->activate
        );

        $deployment->setSequence($deployment->getId());

        // Loop until the entire file is downloaded
        while ($start < $fileSize) {
            $chunkData = $this->call(
                'GET',
                "/functions/{$func->getId()}/deployments/{$deployment->getSequence()}/download",
                ['range' => "bytes=$start-$end"]
            );

            // Send the chunk to the callback function
            $deployment
                ->setData($chunkData)
                ->setStart($start)
                ->setEnd($end);

            $this->callback([$deployment]);

            // Update the range
            $start += Transfer::STORAGE_MAX_CHUNK_SIZE;
            $end += Transfer::STORAGE_MAX_CHUNK_SIZE;

            if ($end >= $fileSize) {
                $end = $fileSize - 1;
            }
        }
    }

    /**
     * @param array<string> $resources
     * @param array<string, int> $report
     * @param array<string, array<string>> $resourceIds
     */
    private function reportMessaging(array $resources, array &$report, array $resourceIds = []): void
    {
        if (\in_array(Resource::TYPE_PROVIDER, $resources)) {
            try {
                $providerQueries = $this->buildQueries(
                    resourceType: Resource::TYPE_PROVIDER,
                    resourceIds: $resourceIds,
                    limit: 1
                );
                $report[Resource::TYPE_PROVIDER] = $this->messaging->listProviders($providerQueries)->total;
            } catch (\Throwable $e) {
                if ($e->getCode() >= 500 && $e->getCode() < 600) {
                    $report[Resource::TYPE_PROVIDER] = 0;
                } else {
                    throw $e;
                }
            }
        }

        if (\in_array(Resource::TYPE_TOPIC, $resources)) {
            try {
                $topicQueries = $this->buildQueries(
                    resourceType: Resource::TYPE_TOPIC,
                    resourceIds: $resourceIds,
                    limit: 1
                );
                $report[Resource::TYPE_TOPIC] = $this->messaging->listTopics($topicQueries)->total;
            } catch (\Throwable $e) {
                if ($e->getCode() >= 500 && $e->getCode() < 600) {
                    $report[Resource::TYPE_TOPIC] = 0;
                } else {
                    throw $e;
                }
            }
        }

        if (\in_array(Resource::TYPE_SUBSCRIBER, $resources)) {
            $subscriberTotal = 0;

            try {
                $lastTopic = null;

                while (true) {
                    $topicQueries = [Query::limit(self::DEFAULT_PAGE_LIMIT)];
                    if ($lastTopic) {
                        $topicQueries[] = Query::cursorAfter($lastTopic);
                    }

                    $topicResponse = $this->messaging->listTopics($topicQueries);
                    if ($topicResponse->total == 0 || empty($topicResponse->topics)) {
                        break;
                    }

                    foreach ($topicResponse->topics as $topic) {
                        $subscriberTotal += $this->messaging->listSubscribers($topic->id, [Query::limit(1)])->total;
                        $lastTopic = $topic->id;
                    }

                    if (\count($topicResponse->topics) < self::DEFAULT_PAGE_LIMIT) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                if ($e->getCode() >= 500 && $e->getCode() < 600) {
                    $subscriberTotal = 0;
                } else {
                    throw $e;
                }
            }

            $report[Resource::TYPE_SUBSCRIBER] = $subscriberTotal;
        }

        if (\in_array(Resource::TYPE_MESSAGE, $resources)) {
            try {
                $messageQueries = $this->buildQueries(
                    resourceType: Resource::TYPE_MESSAGE,
                    resourceIds: $resourceIds,
                    limit: 1
                );
                $report[Resource::TYPE_MESSAGE] = $this->messaging->listMessages($messageQueries)->total;
            } catch (\Throwable $e) {
                if ($e->getCode() >= 500 && $e->getCode() < 600) {
                    $report[Resource::TYPE_MESSAGE] = 0;
                } else {
                    throw $e;
                }
            }
        }
    }

    protected function exportGroupMessaging(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_PROVIDER, $resources)) {
                $this->exportProviders($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_PROVIDER,
                Transfer::GROUP_MESSAGING,
                message: $e->getMessage(),
                code: $e->getCode(),
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_TOPIC, $resources)) {
                $this->exportTopics($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_TOPIC,
                Transfer::GROUP_MESSAGING,
                message: $e->getMessage(),
                code: $e->getCode(),
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_SUBSCRIBER, $resources)) {
                $this->exportSubscribers($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_SUBSCRIBER,
                Transfer::GROUP_MESSAGING,
                message: $e->getMessage(),
                code: $e->getCode(),
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_MESSAGE, $resources)) {
                $this->exportMessages($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_MESSAGE,
                Transfer::GROUP_MESSAGING,
                message: $e->getMessage(),
                code: $e->getCode(),
                previous: $e
            ));
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportProviders(int $batchSize): void
    {
        $lastDocument = null;

        while (true) {
            $providers = [];

            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_PROVIDER) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastDocument) {
                $queries[] = Query::cursorAfter($lastDocument);
            }

            $response = $this->messaging->listProviders($queries);

            if ($response->total == 0) {
                break;
            }

            foreach ($response->providers as $provider) {
                $providers[] = new Provider(
                    $provider->id,
                    $provider->name,
                    $provider->provider,
                    $provider->type,
                    $provider->enabled,
                    $provider->credentials ?? [],
                    $provider->options ?? [],
                    $provider->createdAt ?? '',
                    $provider->updatedAt ?? '',
                );

                $lastDocument = $provider->id;
            }

            $this->callback($providers);

            if (\count($providers) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportTopics(int $batchSize): void
    {
        $lastDocument = null;

        while (true) {
            $topics = [];

            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_TOPIC) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastDocument) {
                $queries[] = Query::cursorAfter($lastDocument);
            }

            $response = $this->messaging->listTopics($queries);

            if ($response->total == 0) {
                break;
            }

            foreach ($response->topics as $topic) {
                $topics[] = new Topic(
                    $topic->id,
                    $topic->name,
                    $topic->subscribe ?? [],
                    $topic->createdAt ?? '',
                    $topic->updatedAt ?? '',
                    $topic->qos,
                    $topic->expiry,
                );

                $lastDocument = $topic->id;
            }

            $this->callback($topics);

            if (\count($topics) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportSubscribers(int $batchSize): void
    {
        $topics = $this->cache->get(Topic::getName());

        foreach ($topics as $topic) {
            /** @var Topic $topic */
            $lastDocument = null;

            while (true) {
                $subscribers = [];

                $queries = [Query::limit($batchSize)];

                if ($lastDocument) {
                    $queries[] = Query::cursorAfter($lastDocument);
                }

                $response = $this->messaging->listSubscribers($topic->getId(), $queries);

                if ($response->total == 0) {
                    break;
                }

                foreach ($response->subscribers as $subscriber) {
                    $subscribers[] = new Subscriber(
                        $subscriber->id,
                        $subscriber->topicId,
                        $subscriber->targetId,
                        $subscriber->userId ?? '',
                        $subscriber->userName ?? '',
                        $subscriber->providerType ?? '',
                        $subscriber->createdAt ?? '',
                        $subscriber->updatedAt ?? '',
                    );

                    $lastDocument = $subscriber->id;
                }

                $this->callback($subscribers);

                if (\count($subscribers) < $batchSize) {
                    break;
                }
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportMessages(int $batchSize): void
    {
        $lastDocument = null;

        while (true) {
            $messages = [];

            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_MESSAGE) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastDocument) {
                $queries[] = Query::cursorAfter($lastDocument);
            }

            $response = $this->messaging->listMessages($queries);

            if ($response->total == 0) {
                break;
            }

            foreach ($response->messages as $message) {
                $messages[] = new Message(
                    $message->id,
                    $message->providerType ?? '',
                    $message->topics ?? [],
                    $message->users ?? [],
                    $message->targets ?? [],
                    $message->data ?? [],
                    (string) $message->status,
                    $message->scheduledAt ?? '',
                    $message->deliveredAt ?? '',
                    $message->deliveryErrors ?? [],
                    $message->deliveredTotal ?? 0,
                    $message->createdAt ?? '',
                    $message->updatedAt ?? '',
                );

                $lastDocument = $message->id;
            }

            $this->callback($messages);

            if (\count($messages) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportSites(int $batchSize): void
    {
        $this->sites = new Sites($this->client);
        /** @var Site|null $lastSite */
        $lastSite = null;

        while (true) {
            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_SITE) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastSite) {
                $queries[] = Query::cursorAfter($lastSite->getId());
            }

            $response = $this->sites->list($queries);

            if ($response->total === 0) {
                return;
            }

            $sites = [];
            $convertedResources = [];

            foreach ($response->sites as $site) {
                $convertedSite = new Site(
                    $site->id,
                    $site->name,
                    $site->framework,
                    $site->buildRuntime,
                    $site->enabled,
                    $site->logging,
                    $site->timeout,
                    $site->installCommand ?? '',
                    $site->buildCommand ?? '',
                    $site->outputDirectory ?? '',
                    $site->adapter ?? 'static',
                    $site->fallbackFile ?? '',
                    $site->runtimeSpecification ?: '',
                    $site->deploymentId ?? '',
                    $site->buildSpecification ?: '',
                    $site->startCommand,
                    $site->deploymentRetention,
                    $site->scopes,
                );
                $sites[] = $convertedSite;
                $convertedResources[] = $convertedSite;

                $variables = $this->sites->listVariables($site->id);
                foreach ($variables->variables ?? [] as $var) {
                    $convertedResources[] = new SiteEnvVar(
                        $var->id,
                        $convertedSite,
                        $var->key,
                        $var->value,
                        $var->secret,
                    );
                }
            }

            $lastSite = $sites[count($sites) - 1];

            $this->callback($convertedResources);

            if (count($sites) < $batchSize) {
                return;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportSiteDeployments(int $batchSize, bool $exportOnlyActive = false): void
    {
        $this->sites = new Sites($this->client);
        $sites = $this->cache->get(Site::getName());

        foreach ($sites as $site) {
            /** @var Site $site */
            $lastDocument = null;

            if ($exportOnlyActive) {
                $activeDeploymentId = $site->getActiveDeployment();
                if (empty($activeDeploymentId)) {
                    continue; // active-only mode: nothing to export for this site
                }

                $deployment = $this->sites->getDeployment($site->getId(), $activeDeploymentId);

                try {
                    $this->exportSiteDeploymentData($site, $deployment);
                } catch (\Throwable $e) {
                    $site->setStatus(Resource::STATUS_ERROR, $e->getMessage());
                }

                continue;
            }

            while (true) {
                $queries = [Query::limit($batchSize)];

                if ($lastDocument) {
                    $queries[] = Query::cursorAfter($lastDocument);
                }

                $response = $this->sites->listDeployments(
                    $site->getId(),
                    $queries
                );

                foreach ($response->deployments as $deployment) {
                    try {
                        $this->exportSiteDeploymentData($site, $deployment);
                    } catch (\Throwable $e) {
                        $site->setStatus(Resource::STATUS_ERROR, $e->getMessage());
                    }

                    $lastDocument = $deployment->id;
                }

                if (count($response->deployments) < $batchSize) {
                    break;
                }
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function exportSiteDeploymentData(Site $site, \Appwrite\Models\Deployment $deployment): void
    {
        $start = 0;
        $end = Transfer::STORAGE_MAX_CHUNK_SIZE - 1;

        $responseHeaders = [];

        $this->call(
            'HEAD',
            "/sites/{$site->getId()}/deployments/{$deployment->id}/download",
            [],
            [],
            $responseHeaders
        );

        if (!\array_key_exists('content-length', $responseHeaders)) {
            $file = $this->call(
                'GET',
                "/sites/{$site->getId()}/deployments/{$deployment->id}/download",
                [],
                [],
                $responseHeaders
            );

            $size = strlen($file);

            if ($end > $size) {
                $end = $size - 1;
            }

            $siteDeployment = new SiteDeployment(
                $deployment->id,
                $site,
                $size,
                $start,
                $end,
                $file,
                $deployment->id === $site->getActiveDeployment()
            );
            $siteDeployment->setSequence($siteDeployment->getId());

            $this->callback([$siteDeployment]);

            return;
        }

        $fileSize = (int) $responseHeaders['content-length'];

        if ($end >= $fileSize) {
            $end = $fileSize - 1;
        }

        $siteDeployment = new SiteDeployment(
            $deployment->id,
            $site,
            $fileSize,
            $start,
            $end,
            '',
            $deployment->id === $site->getActiveDeployment()
        );

        $siteDeployment->setSequence($siteDeployment->getId());

        while ($start < $fileSize) {
            $chunkData = $this->call(
                'GET',
                "/sites/{$site->getId()}/deployments/{$siteDeployment->getSequence()}/download",
                ['range' => "bytes=$start-$end"]
            );

            $siteDeployment
                ->setData($chunkData)
                ->setStart($start)
                ->setEnd($end);

            $this->callback([$siteDeployment]);

            $start += Transfer::STORAGE_MAX_CHUNK_SIZE;
            $end += Transfer::STORAGE_MAX_CHUNK_SIZE;

            if ($end >= $fileSize) {
                $end = $fileSize - 1;
            }
        }
    }

    /**
     * @param array<string> $resources
     * @param array<string, int> $report
     * @param array<string, array<string>> $resourceIds
     */
    private function reportIntegrations(array $resources, array &$report, array $resourceIds = []): void
    {
        if (\in_array(Resource::TYPE_PLATFORM, $resources)) {
            $platformQueries = $this->buildQueries(
                resourceType: Resource::TYPE_PLATFORM,
                resourceIds: $resourceIds,
                limit: 1
            );
            try {
                $report[Resource::TYPE_PLATFORM] = $this->project->listPlatforms($platformQueries)->total;
            } catch (\Throwable) {
                $report[Resource::TYPE_PLATFORM] = 0;
            }
        }

        if (\in_array(Resource::TYPE_API_KEY, $resources)) {
            $keyQueries = $this->buildQueries(
                resourceType: Resource::TYPE_API_KEY,
                resourceIds: $resourceIds,
                limit: 1
            );
            try {
                $report[Resource::TYPE_API_KEY] = $this->project->listKeys($keyQueries)->total;
            } catch (\Throwable) {
                $report[Resource::TYPE_API_KEY] = 0;
            }
        }

        if (\in_array(Resource::TYPE_WEBHOOK, $resources)) {
            $webhookQueries = $this->buildQueries(
                resourceType: Resource::TYPE_WEBHOOK,
                resourceIds: $resourceIds,
                limit: 1
            );
            try {
                $report[Resource::TYPE_WEBHOOK] = $this->webhooks->list($webhookQueries)->total;
            } catch (\Throwable) {
                $report[Resource::TYPE_WEBHOOK] = 0;
            }
        }

        if (\in_array(Resource::TYPE_SMTP, $resources)) {
            // Singleton — one SMTP config per project.
            $report[Resource::TYPE_SMTP] = 1;
        }
    }

    /**
     * @param string $databaseType
     * @param array $database {
     *     id: string,
     *     name: string,
     *     createdAt: string,
     *     updatedAt: string,
     *     enabled: bool,
     *     originalId: string|null,
     *     database: string
     * }
    */
    public static function getDatabase(string $databaseType, array $database): Resource
    {
        switch ($databaseType) {
            case Resource::TYPE_DATABASE_DOCUMENTSDB:
                return DocumentsDB::fromArray($database);
            case Resource::TYPE_DATABASE_VECTORSDB:
                return VectorsDB::fromArray($database);
            default:
                return Database::fromArray($database);
        }
    }

    /**
     * @param int $batchSize
     * @param array<string> $resources
     */
    protected function exportGroupIntegrations(int $batchSize, array $resources): void
    {
        if (\in_array(Resource::TYPE_PLATFORM, $resources)) {
            try {
                $this->exportPlatforms($batchSize);
            } catch (\Throwable $e) {
                $this->addError(new Exception(
                    Resource::TYPE_PLATFORM,
                    Transfer::GROUP_INTEGRATIONS,
                    message: $e->getMessage(),
                    code: $e->getCode(),
                    previous: $e
                ));
            }
        }

        if (\in_array(Resource::TYPE_API_KEY, $resources)) {
            try {
                $this->exportApiKeys($batchSize);
            } catch (\Throwable $e) {
                $this->addError(new Exception(
                    Resource::TYPE_API_KEY,
                    Transfer::GROUP_INTEGRATIONS,
                    message: $e->getMessage(),
                    code: $e->getCode(),
                    previous: $e
                ));
            }
        }

        if (\in_array(Resource::TYPE_WEBHOOK, $resources)) {
            try {
                $this->exportWebhooks($batchSize);
            } catch (\Throwable $e) {
                $this->addError(new Exception(
                    Resource::TYPE_WEBHOOK,
                    Transfer::GROUP_INTEGRATIONS,
                    message: $e->getMessage(),
                    code: $e->getCode(),
                    previous: $e
                ));
            }
        }

        try {
            if (\in_array(Resource::TYPE_SMTP, $resources)) {
                $this->exportSMTP();
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_SMTP,
                Transfer::GROUP_INTEGRATIONS,
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            ));
        }
    }

    /**
     * eg., tables,collections
     * @param string $databaseType
     * @param array{
     *     database: array{
     *        id: string,
     *        name: string,
     *     },
     *     name: string,
     *     id: string,
     *     documentSecurity?: bool,
     *     rowSecurity?: bool,
     *     permissions: ?array<string>,
     *     createdAt: string,
     *     updatedAt: string,
     *     enabled: bool
     * } $entity
     */
    public static function getEntity(string $databaseType, array $entity): Resource
    {
        switch ($databaseType) {
            case Resource::TYPE_DATABASE_DOCUMENTSDB:
                return Collection::fromArray($entity);
            case Resource::TYPE_DATABASE_VECTORSDB:
                return Collection::fromArray($entity);
            default:
                return Table::fromArray($entity);
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportPlatforms(int $batchSize): void
    {
        $lastId = null;

        while (true) {
            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_PLATFORM) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastId !== null) {
                $queries[] = Query::cursorAfter($lastId);
            }

            $response = $this->project->listPlatforms($queries);
            if ($response->total === 0) {
                break;
            }

            $platforms = [];

            // SDK returns the raw platforms array (mixed PlatformWeb/Apple/Android/etc payloads),
            // so we read the unified REST fields directly rather than via typed accessors.
            foreach ($response->platforms as $platform) {
                $platforms[] = new Platform(
                    $platform['$id'] ?? '',
                    $platform['type'] ?? '',
                    $platform['name'] ?? '',
                    $platform['key'] ?? '',
                    $platform['store'] ?? '',
                    $platform['hostname'] ?? '',
                    createdAt: $platform['$createdAt'] ?? '',
                    updatedAt: $platform['$updatedAt'] ?? '',
                );

                $lastId = $platform['$id'] ?? null;
            }

            $this->callback($platforms);

            if (\count($response->platforms) < $batchSize) {
                break;
            }
        }
    }

    /**
     * @throws AppwriteException
     */
    private function exportApiKeys(int $batchSize): void
    {
        $lastId = null;

        while (true) {
            $queries = [Query::limit($batchSize)];

            if ($this->rootResourceId !== '' && $this->rootResourceType === Resource::TYPE_API_KEY) {
                $queries[] = Query::equal('$id', $this->rootResourceId);
                $queries[] = Query::limit(1);
            }

            if ($lastId !== null) {
                $queries[] = Query::cursorAfter($lastId);
            }

            $response = $this->project->listKeys($queries);
            if ($response->total === 0) {
                break;
            }

            $apiKeys = [];

            foreach ($response->keys as $key) {
                $apiKeys[] = new ApiKey(
                    $key->id,
                    $key->name,
                    $key->scopes,
                    $key->expire,
                    $key->accessedAt,
                    $key->sdks,
                    createdAt: $key->createdAt,
                    updatedAt: $key->updatedAt,
                );

                $lastId = $key->id;
            }

            $this->callback($apiKeys);

            if (\count($response->keys) < $batchSize) {
                break;
            }
        }
    }

    /**
     * eg.,documents/attributes
     * @param string $databaseType
     * @param array{
     *     id: string,
     *     collection?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         documentSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     table?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         rowSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     data: array<string, mixed>,
     *     permissions: ?array<string>
     * } $record
     */
    public static function getRecord(string $databaseType, array $record): Resource
    {
        switch ($databaseType) {
            case Resource::TYPE_DATABASE_DOCUMENTSDB:
                return Document::fromArray($record);
            case Resource::TYPE_DATABASE_VECTORSDB:
                return Document::fromArray($record);
            default:
                return Row::fromArray($record);
        }
    }

    public static function getColumn(Table $table, mixed $column): Column
    {
        if ($column instanceof UtopiaDocument) {
            $column = $column->getArrayCopy();
        }

        // Appwrite accepts a format (`email`, `url`, `ip`, `enum`) as a type on
        // an inline column definition, and reports no size for the types whose
        // size the type itself implies. Resolve both the way the server does.
        ['type' => $type, 'format' => $format, 'size' => $size] = Column::resolve($column);

        return match ($type) {
            Column::TYPE_STRING => match ($format) {
                Column::TYPE_EMAIL => new Email(
                    $column['key'],
                    $table,
                    required: $column['required'],
                    default: $column['default'],
                    array: $column['array'],
                    size: $size,
                    createdAt: $column['$createdAt'] ?? '',
                    updatedAt: $column['$updatedAt'] ?? '',
                ),
                Column::TYPE_ENUM => new Enum(
                    $column['key'],
                    $table,
                    elements: $column['elements'],
                    required: $column['required'],
                    default: $column['default'],
                    array: $column['array'],
                    size: $size,
                    createdAt: $column['$createdAt'] ?? '',
                    updatedAt: $column['$updatedAt'] ?? '',
                ),
                Column::TYPE_URL => new URL(
                    $column['key'],
                    $table,
                    required: $column['required'],
                    default: $column['default'],
                    array: $column['array'],
                    size: $size,
                    createdAt: $column['$createdAt'] ?? '',
                    updatedAt: $column['$updatedAt'] ?? '',
                ),
                Column::TYPE_IP => new IP(
                    $column['key'],
                    $table,
                    required: $column['required'],
                    default: $column['default'],
                    array: $column['array'],
                    size: $size,
                    createdAt: $column['$createdAt'] ?? '',
                    updatedAt: $column['$updatedAt'] ?? '',
                ),
                default => new Text(
                    $column['key'],
                    $table,
                    required: $column['required'],
                    default: $column['default'],
                    array: $column['array'],
                    size: $size,
                    createdAt: $column['$createdAt'] ?? '',
                    updatedAt: $column['$updatedAt'] ?? '',
                ),
            },

            Column::TYPE_BOOLEAN => new Boolean(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                array: $column['array'],
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_INTEGER => new Integer(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                array: $column['array'],
                min: $column['min'] ?? null,
                max: $column['max'] ?? null,
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_BIG_INT => new BigInt(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                array: $column['array'],
                min: $column['min'] ?? null,
                max: $column['max'] ?? null,
                signed: $column['signed'] ?? true,
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_FLOAT => new Decimal(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                array: $column['array'],
                min: $column['min'] ?? null,
                max: $column['max'] ?? null,
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_RELATIONSHIP => new Relationship(
                $column['key'],
                $table,
                relatedTable: $column['relatedTable'] ?? $column['relatedCollection'],
                relationType: $column['relationType'],
                twoWay: $column['twoWay'],
                twoWayKey: $column['twoWayKey'],
                onDelete: $column['onDelete'],
                side: $column['side'],
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_DATETIME => new DateTime(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                array: $column['array'],
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_POINT => new Point(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_LINE => new Line(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_POLYGON => new Polygon(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_OBJECT => new ObjectType(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_VECTOR => new Vector(
                $column['key'],
                $table,
                size: $column['size'],
                required: $column['required'],
                default: $column['default'],
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_VARCHAR => new Varchar(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                array: $column['array'],
                size: $size ?: Column::DEFAULT_VARCHAR_SIZE,
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_TEXT => new RegularText(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                array: $column['array'],
                size: $size,
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_MEDIUMTEXT => new MediumText(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                array: $column['array'],
                size: $size,
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),

            Column::TYPE_LONGTEXT => new LongText(
                $column['key'],
                $table,
                required: $column['required'],
                default: $column['default'],
                array: $column['array'],
                size: $size,
                createdAt: $column['$createdAt'] ?? '',
                updatedAt: $column['$updatedAt'] ?? '',
            ),


            default => throw new \InvalidArgumentException("Unsupported column type: {$type}"),
        };
    }

    /**
     * Convert a raw attribute array to an Attribute resource.
     * This is now a thin wrapper that uses getColumn() and then converts to Attribute.
     * Since Collection extends Table, we can pass it directly to getColumn().
     *
     * @param Collection $collection
     * @param mixed $attribute
     * @return Attribute
     */
    public static function getAttribute(Collection $collection, mixed $attribute): Attribute
    {
        // Use getColumn() to create the Column resource, then convert to Attribute
        // This eliminates duplication - all type-specific logic is in getColumn()
        return self::getColumn($collection, $attribute)->getAttribute();
    }

    /**
     * Build queries with optional filtering by resource IDs
     */
    private function buildQueries(
        string $resourceType,
        array $resourceIds,
        ?string $cursor = null,
        int $limit = self::DEFAULT_PAGE_LIMIT
    ): array {
        $queries = [];

        if (!empty($resourceIds[$resourceType])) {
            $ids = (array) $resourceIds[$resourceType];

            $queries[] = Query::equal('$id', $ids);
        }

        if ($cursor) {
            $queries[] = Query::cursorAfter($cursor);
        } else {
            $queries[] = Query::limit($limit);
        }

        return $queries;
    }
}
