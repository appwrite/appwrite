<?php

namespace Utopia\Usage;

use ArrayObject;

/**
 * Usage Metric
 *
 * Represents a single usage metric data point containing information about
 * resource usage, performance metrics, or other measurable data points.
 *
 * This class extends ArrayObject to provide both array-like access and
 * type-safe getter methods for common metric attributes.
 *
 * Example:
 * ```php
 * $metric = new Metric([
 *     '$id' => 'unique-id',
 *     'metric' => 'bandwidth',
 *     'value' => 1024,
 *     'time' => '2025-12-09 10:00:00',
 *     'path' => '/v1/storage/files',
 *     'method' => 'POST',
 *     'status' => '201',
 *     'service' => 'storage',
 *     'resourceType' => 'bucket',
 *     'resourceId' => 'abc123',
 *     'resourceInternalId' => '42',
 *     'teamId' => 'team_x',
 *     'teamInternalId' => '7',
 *     'country' => 'us',
 *     'region' => 'us-east',
 *     'hostname' => 'app.example.com',
 *     'osName' => 'iOS',
 *     'clientName' => 'Appwrite SDK',
 *     'deviceName' => 'smartphone',
 * ]);
 *
 * echo $metric->getMetric(); // 'bandwidth'
 * echo $metric->getValue();  // 1024
 * echo $metric->getPath();   // '/v1/storage/files'
 * ```
 *
 * @extends ArrayObject<string, mixed>
 */
class Metric extends ArrayObject
{
    /**
     * Event-specific column names that are extracted from tags into dedicated columns.
     */
    public const EVENT_COLUMNS = [
        'path', 'method', 'status',
        'service', 'resourceType', 'resourceId', 'resourceInternalId',
        'teamId', 'teamInternalId',
        'country', 'region', 'hostname', 'ip',
        // request attributes (firewall rule matching)
        'protocol', 'accept', 'acceptLanguage', 'queryKeys',
        // ip reputation (placeholder — populated later; bounded verdict enum:
        // clean/low/suspicious/block)
        'ipReputation',
        // premium geo
        'city', 'continentCode', 'subdivisions',
        'postalCode', 'latitude', 'longitude', 'timeZone', 'weatherCode',
        'isp', 'autonomousSystemNumber', 'autonomousSystemOrganization',
        'connectionType', 'connectionUsageType', 'connectionOrganization',
        'osCode', 'osName', 'osVersion',
        'clientType', 'clientCode', 'clientName', 'clientVersion',
        'clientEngine', 'clientEngineVersion',
        // sdk identity
        'sdk', 'sdkVersion',
        'deviceName', 'deviceBrand', 'deviceModel',
    ];

    /**
     * Gauge-specific column names that are extracted from tags into dedicated columns.
     */
    public const GAUGE_COLUMNS = ['service', 'resourceType', 'teamId', 'teamInternalId', 'resourceId', 'resourceInternalId', 'ordinal'];

    /**
     * Construct a new metric object.
     *
     * Initializes the metric with the provided data array.
     * The array can contain any attributes, but common ones include:
     * - $id: Unique identifier for the metric
     * - metric: Name/type of the metric being tracked
     * - value: Numeric value of the metric
     * - time: Timestamp when the metric was recorded
     * - tenant: Tenant ID for multi-tenant environments
     *
     * Event-only dimension columns (see EVENT_COLUMNS):
     * - path / method / status: HTTP shape
     * - service: API service segment (storage, databases, …)
     * - resourceType / resourceId / resourceInternalId: resource identity
     * - teamId / teamInternalId: owning team identity
     * - country / region / hostname / ip: geographic + caller origin
     * - protocol / accept / acceptLanguage / queryKeys: request attributes (firewall rule matching)
     * - ipReputation: IP reputation verdict (placeholder; clean/low/suspicious/block)
     * - city / continentCode / subdivisions: premium geo location fields
     * - postalCode / latitude / longitude / timeZone / weatherCode: premium geo location fields
     * - isp / autonomousSystemNumber / autonomousSystemOrganization: premium network origin
     * - connectionType / connectionUsageType / connectionOrganization: premium connection intelligence
     * - osCode / osName / osVersion: parsed user-agent OS fields
     * - clientType / clientCode / clientName / clientVersion: parsed client
     * - clientEngine / clientEngineVersion: parsed client engine
     * - sdk / sdkVersion: originating SDK name and version
     * - deviceName / deviceBrand / deviceModel: parsed device fields
     *
     * Gauge-only dimension columns (see GAUGE_COLUMNS):
     * - teamId / teamInternalId / resourceId / resourceInternalId
     * - ordinal: replica ordinal for multi-node resources (0 is the primary)
     *
     * @param  array<string, mixed>  $input  Metric data
     */
    public function __construct(array $input = [])
    {
        parent::__construct($input);
    }

    /**
     * Get metric ID.
     *
     * Returns the unique identifier for this metric entry.
     * This is typically a UUID or auto-generated ID from the storage backend.
     *
     * @return string The metric ID, or empty string if not set
     */
    public function getId(): string
    {
        $id = $this->getAttribute('$id', '');
        return is_string($id) ? $id : '';
    }

    /**
     * Get metric name.
     *
     * Returns the name or type of metric being tracked.
     * Examples: 'bandwidth', 'requests', 'storage', 'executions'
     *
     * @return string The metric name, or empty string if not set
     */
    public function getMetric(): string
    {
        $metric = $this->getAttribute('metric', '');
        return is_string($metric) ? $metric : '';
    }

    /**
     * Get metric value.
     *
     * Returns the numeric value associated with this metric.
     * For example, number of requests, bytes transferred, or execution count.
     *
     * Aggregated queries (SUM, argMax, AVG) can produce values that exceed
     * PHP_INT_MAX or include fractional parts, so this returns int|float.
     *
     * @param  int|float|null  $default  Default value to return if not set
     * @return int|float|null The metric value, or the default if not set or invalid
     */
    public function getValue(int|float|null $default = null): int|float|null
    {
        $value = $this->getAttribute('value', $default ?? 0);
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        return $default;
    }

    /**
     * Get metric type.
     *
     * Returns the type of this metric based on which table it was stored in.
     * Values:
     * - 'event': Additive metrics (bandwidth, requests, etc.) aggregated with SUM
     * - 'gauge': Point-in-time metrics (storage, user count, etc.) aggregated with argMax
     *
     * Note: The type is no longer stored in the table schema (since table choice implies type),
     * but this method is kept for backward compatibility. It reads from the 'type' attribute
     * which callers may still set.
     *
     * @return string The type identifier, defaults to 'event'
     */
    public function getType(): string
    {
        $type = $this->getAttribute('type', 'event');
        return is_string($type) ? $type : 'event';
    }

    /**
     * Get timestamp.
     *
     * Returns the timestamp when this metric was recorded or the
     * aggregation period start time. Format depends on the storage backend,
     * typically ISO 8601 or database datetime format.
     *
     * @return string|null The timestamp string, or null if not set
     */
    public function getTime(): ?string
    {
        $time = $this->getAttribute('time', null);
        return is_string($time) ? $time : null;
    }

    /**
     * Get API endpoint path (event metrics only).
     *
     * @return string|null The path, or null if not set
     */
    public function getPath(): ?string
    {
        $path = $this->getAttribute('path', null);
        return is_string($path) ? $path : null;
    }

    /**
     * Get HTTP method (event metrics only).
     *
     * @return string|null The HTTP method (GET, POST, etc.), or null if not set
     */
    public function getMethod(): ?string
    {
        $method = $this->getAttribute('method', null);
        return is_string($method) ? $method : null;
    }

    /**
     * Get HTTP status code (event metrics only).
     *
     * @return string|null The status code as string, or null if not set
     */
    public function getStatus(): ?string
    {
        $status = $this->getAttribute('status', null);
        return is_string($status) ? $status : null;
    }

    /**
     * Get resource type (event metrics only).
     *
     * @return string|null The resource type, or null if not set
     */
    public function getResourceType(): ?string
    {
        $resourceType = $this->getAttribute('resourceType', null);
        return is_string($resourceType) ? $resourceType : null;
    }

    /**
     * Get resource ID (event metrics only).
     *
     * @return string|null The resource ID, or null if not set
     */
    public function getResourceId(): ?string
    {
        $resourceId = $this->getAttribute('resourceId', null);
        return is_string($resourceId) ? $resourceId : null;
    }

    /**
     * Get country code (event metrics only).
     *
     * @return string|null ISO 3166-1 alpha-2 country code, or null if not set
     */
    public function getCountry(): ?string
    {
        $country = $this->getAttribute('country', null);
        return is_string($country) ? $country : null;
    }

    /**
     * Get service (event metrics only).
     *
     * @return string|null
     */
    public function getService(): ?string
    {
        $v = $this->getAttribute('service', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get internal resource id (event/gauge metrics).
     *
     * @return string|null
     */
    public function getResourceInternalId(): ?string
    {
        $v = $this->getAttribute('resourceInternalId', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get replica ordinal (gauge metrics). 0 is the primary; 1+ are replicas.
     */
    public function getOrdinal(): ?string
    {
        $v = $this->getAttribute('ordinal', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get team id (event/gauge metrics).
     */
    public function getTeamId(): ?string
    {
        $v = $this->getAttribute('teamId', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get team internal id (event/gauge metrics).
     */
    public function getTeamInternalId(): ?string
    {
        $v = $this->getAttribute('teamInternalId', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get region (event metrics).
     */
    public function getRegion(): ?string
    {
        $v = $this->getAttribute('region', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get caller hostname (event metrics).
     */
    public function getHostname(): ?string
    {
        $v = $this->getAttribute('hostname', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get caller IP address (event metrics).
     */
    public function getIp(): ?string
    {
        $v = $this->getAttribute('ip', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get OS short code (event metrics).
     */
    public function getOsCode(): ?string
    {
        $v = $this->getAttribute('osCode', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get OS name (event metrics).
     */
    public function getOsName(): ?string
    {
        $v = $this->getAttribute('osName', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get OS version (event metrics).
     */
    public function getOsVersion(): ?string
    {
        $v = $this->getAttribute('osVersion', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get client type (event metrics).
     */
    public function getClientType(): ?string
    {
        $v = $this->getAttribute('clientType', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get client short code (event metrics).
     */
    public function getClientCode(): ?string
    {
        $v = $this->getAttribute('clientCode', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get client name (event metrics).
     */
    public function getClientName(): ?string
    {
        $v = $this->getAttribute('clientName', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get client version (event metrics).
     */
    public function getClientVersion(): ?string
    {
        $v = $this->getAttribute('clientVersion', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get client engine (event metrics).
     */
    public function getClientEngine(): ?string
    {
        $v = $this->getAttribute('clientEngine', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get client engine version (event metrics).
     */
    public function getClientEngineVersion(): ?string
    {
        $v = $this->getAttribute('clientEngineVersion', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get device name (event metrics).
     */
    public function getDeviceName(): ?string
    {
        $v = $this->getAttribute('deviceName', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get device brand (event metrics).
     */
    public function getDeviceBrand(): ?string
    {
        $v = $this->getAttribute('deviceBrand', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get device model (event metrics).
     */
    public function getDeviceModel(): ?string
    {
        $v = $this->getAttribute('deviceModel', null);
        return is_string($v) ? $v : null;
    }

    /**
     * Get tenant ID.
     *
     * Returns the tenant identifier when using shared tables in multi-tenant
     * architectures. This allows data isolation at the application level while
     * sharing the same database tables.
     *
     * @return string|null The tenant ID, or null if not set or not using multi-tenancy
     */
    public function getTenant(): ?string
    {
        $tenant = $this->getAttribute('tenant');

        if ($tenant === null) {
            return null;
        }

        return is_string($tenant) ? $tenant : (is_numeric($tenant) ? (string) $tenant : null);
    }

    /**
     * Get all attributes.
     *
     * Returns all metric data as an associative array.
     * This includes both standard fields (id, metric, value, etc.) and
     * any custom attributes that were set on the metric.
     *
     * @return array<string, mixed> All metric attributes
     */
    public function getAttributes(): array
    {
        $attributes = [];

        foreach ($this as $key => $value) {
            $attributes[$key] = $value;
        }

        return $attributes;
    }

    /**
     * Get a specific attribute.
     *
     * Retrieves the value of a named attribute. If the attribute doesn't exist,
     * returns the provided default value.
     *
     * This is a generic accessor - prefer using the type-safe getters
     * (getId(), getMetric(), etc.) for standard attributes.
     *
     * @param  string  $name  The attribute name to retrieve
     * @param  mixed  $default  Default value if attribute is not set
     * @return mixed The attribute value or default
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        if (isset($this[$name])) {
            return $this[$name];
        }

        return $default;
    }

    /**
     * Set a specific attribute.
     *
     * Sets or updates the value of a named attribute.
     * Returns the metric instance for method chaining.
     *
     * Example:
     * ```php
     * $metric->setAttribute('custom', 'value')
     *        ->setAttribute('another', 123);
     * ```
     *
     * @param  string  $key  The attribute name
     * @param  mixed  $value  The attribute value
     * @return static This metric instance for chaining
     */
    public function setAttribute(string $key, mixed $value): static
    {
        $this[$key] = $value;

        return $this;
    }

    /**
     * Check if an attribute exists.
     *
     * Determines whether a named attribute is set on this metric,
     * regardless of its value (including null).
     *
     * @param  string  $name  The attribute name to check
     * @return bool True if the attribute exists, false otherwise
     */
    public function hasAttribute(string $name): bool
    {
        return isset($this[$name]);
    }

    /**
     * Remove an attribute.
     *
     * Removes a named attribute from the metric.
     * Returns the metric instance for method chaining.
     *
     * @param  string  $name  The attribute name to remove
     * @return static This metric instance for chaining
     */
    public function removeAttribute(string $name): static
    {
        unset($this[$name]);

        /** @var static */
        return $this;
    }

    /**
     * Check if the metric is empty.
     *
     * A metric is considered empty if it has no ID set.
     * This is useful for checking if a query returned valid results.
     *
     * @return bool True if the metric has no ID, false otherwise
     */
    public function isEmpty(): bool
    {
        return empty($this->getId());
    }

    /**
     * Convert to array.
     *
     * Returns a plain PHP array representation of the metric.
     * This is useful for serialization, JSON encoding, or passing
     * to functions that expect arrays.
     *
     * @return array<string, mixed> Array representation of the metric
     */
    public function toArray(): array
    {
        return $this->getArrayCopy();
    }

    /**
     * Get event table schema definition.
     *
     * Returns the attribute schema for the events table which stores
     * raw request events with metadata columns for path, method, status,
     * resourceType, and resourceId.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getEventSchema(): array
    {
        $stringColumn = static fn (string $id, int $size): array => [
            '$id' => $id,
            'type' => 'string',
            'size' => $size,
            'required' => false,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ];

        return [
            [
                '$id' => 'metric',
                'type' => 'string',
                'size' => 255,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'value',
                'type' => 'integer',
                'size' => 0,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'time',
                'type' => 'datetime',
                'format' => '',
                'size' => 0,
                'signed' => true,
                'required' => false,
                'array' => false,
                'filters' => ['datetime'],
            ],
            $stringColumn('path', 1024),
            $stringColumn('method', 16),
            $stringColumn('status', 16),
            $stringColumn('service', 256),
            $stringColumn('resourceType', 256),
            $stringColumn('resourceId', 255),
            $stringColumn('resourceInternalId', 255),
            $stringColumn('teamId', 255),
            $stringColumn('teamInternalId', 255),
            $stringColumn('country', 2),
            $stringColumn('region', 64),
            $stringColumn('hostname', 255),
            $stringColumn('ip', 45),
            // request attributes (firewall rule matching)
            $stringColumn('protocol', 16),
            $stringColumn('accept', 1024),
            $stringColumn('acceptLanguage', 256),
            $stringColumn('queryKeys', 1024),
            // ip reputation (placeholder — bounded verdict enum)
            $stringColumn('ipReputation', 32),
            // premium geo
            $stringColumn('city', 256),
            $stringColumn('continentCode', 2),
            $stringColumn('subdivisions', 256),
            $stringColumn('postalCode', 32),
            $stringColumn('latitude', 32),
            $stringColumn('longitude', 32),
            $stringColumn('timeZone', 64),
            $stringColumn('weatherCode', 16),
            $stringColumn('isp', 256),
            $stringColumn('autonomousSystemNumber', 255),
            $stringColumn('autonomousSystemOrganization', 256),
            $stringColumn('connectionType', 256),
            $stringColumn('connectionUsageType', 256),
            $stringColumn('connectionOrganization', 256),
            $stringColumn('osCode', 256),
            $stringColumn('osName', 256),
            $stringColumn('osVersion', 255),
            $stringColumn('clientType', 256),
            $stringColumn('clientCode', 256),
            $stringColumn('clientName', 256),
            $stringColumn('clientVersion', 255),
            $stringColumn('clientEngine', 256),
            $stringColumn('clientEngineVersion', 255),
            // sdk identity
            $stringColumn('sdk', 256),
            $stringColumn('sdkVersion', 255),
            $stringColumn('deviceName', 256),
            $stringColumn('deviceBrand', 256),
            $stringColumn('deviceModel', 255),
        ];
    }

    /**
     * Get gauge table schema definition.
     *
     * Returns the attribute schema for the gauges table which stores
     * simple resource snapshots (metric, value, time, tags).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getGaugeSchema(): array
    {
        $stringColumn = static fn (string $id, int $size): array => [
            '$id' => $id,
            'type' => 'string',
            'size' => $size,
            'required' => false,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ];

        return [
            [
                '$id' => 'metric',
                'type' => 'string',
                'size' => 255,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'value',
                'type' => 'integer',
                'size' => 0,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'time',
                'type' => 'datetime',
                'format' => '',
                'size' => 0,
                'signed' => true,
                'required' => false,
                'array' => false,
                'filters' => ['datetime'],
            ],
            $stringColumn('service', 256),
            $stringColumn('resourceType', 256),
            $stringColumn('teamId', 255),
            $stringColumn('teamInternalId', 255),
            $stringColumn('resourceId', 255),
            $stringColumn('resourceInternalId', 255),
            $stringColumn('ordinal', 255),
        ];
    }

    /**
     * Get combined schema (backward compat).
     *
     * Returns the event schema which is a superset. This preserves
     * backward compatibility with code that calls Metric::getSchema().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getSchema(): array
    {
        return self::getEventSchema();
    }

    /**
     * Get event table indexes.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getEventIndexes(): array
    {
        $indexed = [
            'path', 'method', 'status',
            'service', 'resourceType', 'resourceId', 'resourceInternalId',
            'teamId', 'teamInternalId',
            'country', 'region', 'hostname', 'ip',
            'osName', 'clientType', 'clientName', 'deviceName',
            // request attributes (firewall rule matching, exact-match)
            'protocol', 'accept', 'acceptLanguage', 'queryKeys',
            // ip reputation (placeholder — bounded verdict enum, exact-match)
            'ipReputation',
            // premium geo (latitude/longitude are display-only: no exact-match
            // filtering, so intentionally left unindexed)
            'postalCode', 'timeZone', 'weatherCode',
        ];

        $setIndexed = [
            'status', 'method', 'country', 'service', 'clientType', 'osName',
            // low-cardinality request/geo dims filtered by equality. accept/
            // acceptLanguage/queryKeys are unbounded caller input, so they use
            // bloom_filter (below) instead of an unlimited set(0) index.
            'protocol', 'ipReputation', 'timeZone', 'weatherCode',
        ];

        // Columns whose full length exceeds the SQL adapter's max index key
        // length (768 bytes) must be indexed on a prefix; ClickHouse ignores
        // the prefix and indexes the whole value.
        $prefixed = [
            'path' => 255,
            'accept' => 255,
            'queryKeys' => 255,
        ];

        return array_map(
            static function (string $col) use ($setIndexed, $prefixed): array {
                $entry = [
                    '$id' => 'index-' . $col,
                    'type' => 'key',
                    'attributes' => [$col],
                    'indexType' => in_array($col, $setIndexed, true) ? 'set(0)' : 'bloom_filter',
                ];
                if (isset($prefixed[$col])) {
                    $entry['lengths'] = [$prefixed[$col]];
                }
                return $entry;
            },
            $indexed,
        );
    }

    /**
     * Get gauge table indexes.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getGaugeIndexes(): array
    {
        $indexed = ['service', 'resourceType', 'resourceId', 'resourceInternalId', 'teamId', 'teamInternalId', 'ordinal'];

        $setIndexed = ['service', 'resourceType', 'ordinal'];

        return array_map(
            static fn (string $col): array => [
                '$id' => 'index-' . $col,
                'type' => 'key',
                'attributes' => [$col],
                'indexType' => in_array($col, $setIndexed, true) ? 'set(0)' : 'bloom_filter',
            ],
            $indexed,
        );
    }

    /**
     * Get combined indexes (backward compat).
     *
     * Returns the event indexes. This preserves backward compatibility
     * with code that calls Metric::getIndexes().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getIndexes(): array
    {
        return self::getEventIndexes();
    }

    /**
     * Extract and normalize dimension columns from a tags array.
     *
     * For the given metric type ('event' or 'gauge'):
     * - Pulls every known column out of $tags.
     * - Coerces scalars to string, empty string to null.
     * - Lowercases country and region.
     * - Throws if $tags contains any unknown key (strict — no JSON catch-all).
     *
     * @param  array<string, mixed>  $tags
     * @param  string  $type  'event' or 'gauge'
     * @return array<string, string|null>
     * @throws \Exception When an unknown column key is present in $tags.
     */
    public static function extractColumns(array $tags, string $type): array
    {
        $allowed = $type === 'gauge' ? self::GAUGE_COLUMNS : self::EVENT_COLUMNS;

        $columns = [];
        foreach ($allowed as $col) {
            $val = $tags[$col] ?? null;
            unset($tags[$col]);
            if (is_string($val)) {
                $val = $val === '' ? null : $val;
            } elseif (is_scalar($val)) {
                $val = (string) $val;
            } else {
                $val = null;
            }
            if (($col === 'country' || $col === 'region') && is_string($val)) {
                $val = strtolower($val);
            }
            $columns[$col] = $val;
        }

        if (!empty($tags)) {
            $unknown = array_key_first($tags);
            throw new \Exception("Unknown column '{$unknown}' for {$type}");
        }

        return $columns;
    }

    /**
     * Validate metric data against schema.
     *
     * Validates that metric data conforms to the schema definition.
     * Checks for:
     * - Required attributes are present
     * - Data types match schema types
     * - String sizes don't exceed limits
     * - Values are in valid ranges
     *
     * @param  array<string, mixed>  $data  The metric data to validate
     * @param  string  $type  The metric type ('event' or 'gauge') to validate against
     * @throws \Exception If validation fails
     */
    public static function validate(array $data, string $type = 'event'): void
    {
        $schema = $type === 'gauge' ? self::getGaugeSchema() : self::getEventSchema();

        foreach ($schema as $attribute) {
            /** @var string $attrId */
            $attrId = $attribute['$id'];
            $required = $attribute['required'] ?? false;
            $attrType = $attribute['type'] ?? 'string';
            /** @var int $size */
            $size = $attribute['size'] ?? 0;

            // Check if required attribute is present
            if ($required && !isset($data[$attrId])) {
                throw new \Exception("Required attribute '{$attrId}' is missing");
            }

            // Skip validation if not present and not required
            if (!isset($data[$attrId])) {
                continue;
            }

            $value = $data[$attrId];

            // Validate based on attribute type
            match ($attrType) {
                'string' => self::validateStringAttribute($attrId, $value, $size),
                'integer' => self::validateIntegerAttribute($attrId, $value),
                'datetime' => self::validateDatetimeAttribute($attrId, $value),
                default => null,
            };
        }
    }

    /**
     * Validate string attribute value.
     *
     * @throws \Exception
     */
    private static function validateStringAttribute(string $attrId, mixed $value, int $size): void
    {
        if (!is_string($value)) {
            throw new \Exception("Attribute '{$attrId}' must be a string, got " . gettype($value));
        }

        if ($size > 0 && strlen($value) > $size) {
            throw new \Exception("Attribute '{$attrId}' exceeds maximum size of {$size} characters");
        }
    }

    /**
     * Validate integer attribute value.
     *
     * @throws \Exception
     */
    private static function validateIntegerAttribute(string $attrId, mixed $value): void
    {
        if (!is_int($value)) {
            throw new \Exception("Attribute '{$attrId}' must be an integer, got " . gettype($value));
        }
    }

    /**
     * Validate datetime attribute value.
     *
     * @throws \Exception
     */
    private static function validateDatetimeAttribute(string $attrId, mixed $value): void
    {
        if ($value instanceof \DateTime) {
            return; // Valid DateTime object
        }

        if (!is_string($value)) {
            throw new \Exception("Attribute '{$attrId}' must be a DateTime object or string, got " . gettype($value));
        }

        try {
            new \DateTime($value);
        } catch (\Exception $e) {
            throw new \Exception("Attribute '{$attrId}' is not a valid datetime string: {$e->getMessage()}");
        }
    }
}
