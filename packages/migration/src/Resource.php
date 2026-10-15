<?php

namespace Utopia\Migration;

abstract class Resource implements \JsonSerializable
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    public const STATUS_SKIPPED = 'skip';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_WARNING = 'warning';

    /**
     * For some transfers (namely Firebase) we have to keep resources in cache that do not necessarily need to be Transferred
     * This status is used to mark resources that are not going to be transferred but are still needed for the transfer to work
     * e.g Documents are required for Database transfers because of schema tracing in firebase
     */
    public const STATUS_DISREGARDED = 'disregarded';

    // Master Resources
    public const TYPE_BUCKET = 'bucket';

    public const TYPE_TABLE = 'table';

    public const TYPE_DATABASE = 'database';

    public const TYPE_DATABASE_LEGACY = 'legacy';

    public const TYPE_DATABASE_TABLESDB = 'tablesdb';

    public const TYPE_DATABASE_DOCUMENTSDB = 'documentsdb';
    public const TYPE_DATABASE_VECTORSDB = 'vectorsdb';

    public const TYPE_ROW = 'row';

    public const TYPE_FILE = 'file';

    public const TYPE_USER = 'user';

    public const TYPE_TEAM = 'team';

    public const TYPE_MEMBERSHIP = 'membership';

    public const TYPE_FUNCTION = 'function';

    public const TYPE_SITE = 'site';

    public const TYPE_INDEX = 'index';

    public const TYPE_PROVIDER = 'provider';

    public const TYPE_TOPIC = 'topic';

    // Children (Resources that are created by other resources)

    public const TYPE_COLUMN = 'column';

    public const TYPE_DEPLOYMENT = 'deployment';

    public const TYPE_SITE_DEPLOYMENT = 'site-deployment';

    public const TYPE_SITE_VARIABLE = 'site-variable';

    public const TYPE_HASH = 'hash';

    public const TYPE_AUTH_METHODS = 'auth-methods';

    public const TYPE_POLICIES = 'policies';

    // One type shared by all OAuth2 provider Resource classes (dispatch is by
    // `instanceof` on the destination). A per-provider type would overflow the
    // migration document's 3KB `statusCounters` column when OAuth is selected.
    public const TYPE_OAUTH2_PROVIDER = 'oauth2-provider';

    public const TYPE_ENVIRONMENT_VARIABLE = 'environment-variable';

    // Integrations
    public const TYPE_PLATFORM = 'platform';
    public const TYPE_API_KEY = 'api-key';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_SMTP = 'smtp';

    // Project (per-project singleton/settings resources)
    public const TYPE_PROJECT_VARIABLE = 'project-variable';
    public const TYPE_PROJECT_PROTOCOLS = 'project-protocols';
    public const TYPE_PROJECT_LABELS = 'project-labels';
    public const TYPE_PROJECT_SERVICES = 'project-services';
    public const TYPE_PROJECT_EMAIL_TEMPLATE = 'project-email-template';

    // Domains
    public const TYPE_RULE = 'rule';

    // Messaging
    public const TYPE_SUBSCRIBER = 'subscriber';
    public const TYPE_MESSAGE = 'message';

    // Backups
    public const TYPE_BACKUP_POLICY = 'backup-policy';

    // legacy terminologies
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_ATTRIBUTE = 'attribute';
    public const TYPE_COLLECTION = 'collection';

    private const TYPE_MAP = [
        Resource::TYPE_ROW    => Resource::TYPE_DOCUMENT,
        Resource::TYPE_COLUMN => Resource::TYPE_ATTRIBUTE,
        Resource::TYPE_TABLE  => Resource::TYPE_COLLECTION,
    ];

    public const ALL_RESOURCES = [
        self::TYPE_COLUMN,
        self::TYPE_BUCKET,
        self::TYPE_TABLE,
        self::TYPE_DATABASE,
        self::TYPE_DATABASE_VECTORSDB,
        self::TYPE_DATABASE_DOCUMENTSDB,
        self::TYPE_ROW,
        self::TYPE_FILE,
        self::TYPE_FUNCTION,
        self::TYPE_DEPLOYMENT,
        self::TYPE_SITE,
        self::TYPE_SITE_DEPLOYMENT,
        self::TYPE_SITE_VARIABLE,
        self::TYPE_HASH,
        self::TYPE_INDEX,
        self::TYPE_USER,
        self::TYPE_ENVIRONMENT_VARIABLE,
        self::TYPE_TEAM,
        self::TYPE_MEMBERSHIP,
        self::TYPE_AUTH_METHODS,
        self::TYPE_POLICIES,
        self::TYPE_OAUTH2_PROVIDER,
        self::TYPE_PLATFORM,
        self::TYPE_API_KEY,
        self::TYPE_WEBHOOK,
        self::TYPE_SMTP,
        self::TYPE_PROJECT_VARIABLE,
        self::TYPE_PROJECT_PROTOCOLS,
        self::TYPE_PROJECT_LABELS,
        self::TYPE_PROJECT_SERVICES,
        self::TYPE_PROJECT_EMAIL_TEMPLATE,
        self::TYPE_RULE,
        self::TYPE_PROVIDER,
        self::TYPE_TOPIC,
        self::TYPE_SUBSCRIBER,
        self::TYPE_MESSAGE,
        self::TYPE_BACKUP_POLICY,

        // legacy
        self::TYPE_DOCUMENT,
        self::TYPE_ATTRIBUTE,
        self::TYPE_COLLECTION,
    ];

    // index terminology is same for all
    public const DATABASE_TYPE_RESOURCE_MAP = [
        self::TYPE_DATABASE => [
            'entity' => self::TYPE_TABLE,
            'field' => self::TYPE_COLUMN,
            'record' => self::TYPE_ROW,
        ],
        self::TYPE_DATABASE_DOCUMENTSDB => [
            'entity' => self::TYPE_COLLECTION,
            // HACK: not required in documentsdb but adding it for consistency in the db reader(not gonna impact)
            'field' => self::TYPE_ATTRIBUTE,
            'record' => self::TYPE_DOCUMENT,
        ],
        self::TYPE_DATABASE_VECTORSDB => [
            'entity' => self::TYPE_COLLECTION,
            'field' => self::TYPE_ATTRIBUTE,
            'record' => self::TYPE_DOCUMENT,
        ]
    ];

    public const ENTITY_TYPE_RESOURCE_MAP = [
        self::TYPE_TABLE => [
            'field' => self::TYPE_COLUMN,
            'record' => self::TYPE_ROW,
            'index' => self::TYPE_INDEX
        ],
        self::TYPE_COLLECTION => [
            'field' => self::TYPE_ATTRIBUTE,
            'record' => self::TYPE_DOCUMENT,
            'index' => self::TYPE_INDEX
        ],
    ];

    protected string $id = '';

    protected string $originalId = '';

    protected string $sequence = '';

    protected string $createdAt = '';
    protected string $updatedAt = '';

    protected string $status = self::STATUS_PENDING;

    protected string $message = '';

    /**
     * @var array<string>
     */
    protected array $permissions = [];

    abstract public static function getName(): string;

    abstract public function getGroup(): string;

    public static function isSupported(string|array $types, array $resources): bool
    {
        $allTypes = [];
        $types = (array) $types;

        foreach ($types as $type) {
            $allTypes[] = $type;
            if (isset(self::TYPE_MAP[$type])) {
                $allTypes[] = self::TYPE_MAP[$type];
            }
        }

        return (bool) \array_intersect($resources, $allTypes);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getOriginalId(): string
    {
        return $this->originalId;
    }

    public function setOriginalId(string $originalId): self
    {
        $this->originalId = $originalId;

        return $this;
    }

    public function getSequence(): string
    {
        return $this->sequence;
    }

    public function setSequence(string $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status, string $message = ''): self
    {
        $this->status = $status;
        $this->message = $message;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * @param  array<string>  $permissions
     */
    public function setPermissions(array $permissions): self
    {
        $this->permissions = $permissions;

        return $this;
    }

    /**
     * @returns string
     */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * @returns string
     */
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }


    public function setCreatedAt(string $date): self
    {
        $this->createdAt = $date;

        return $this;
    }

    public function setUpdatedAt(string $date): self
    {
        $this->updatedAt = $date;

        return $this;
    }
}
