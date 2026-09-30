<?php

namespace Utopia\DNS;

use Utopia\DNS\Message\Record;

/**
 * An administrative unit containing DNS records for a domain and its subdomains.
 */
final readonly class Zone
{
    public string $name;

    /**
     * @var list<Record>
     */
    public array $records;

    /**
     * @param string $name The zone domain name (usually apex)
     * @param list<Record> $records DNS records in this zone (excluding SOA)
     * @param Record $soa The SOA record for this zone
     */
    public function __construct(
        string $name,
        array $records,
        public Record $soa,
    ) {
        if ($soa->type !== Record::TYPE_SOA) {
            throw new \InvalidArgumentException('SOA parameter must be a Record with TYPE_SOA');
        }

        $this->name = strtolower($name);
        if ($soa->name !== $this->name) {
            throw new \InvalidArgumentException("SOA record name must match zone name: expected '$this->name', got '$soa->name'");
        }

        $zoneSuffix = $this->name === '.' ? '.' : ".$this->name";

        // Validate that all records belong to the zone
        foreach ($records as $record) {
            if ($record->type === Record::TYPE_SOA) {
                throw new \InvalidArgumentException('SOA records should be passed as the $soa parameter, not in $records');
            }
            if ($this->name !== '.' && $record->name !== $this->name && !str_ends_with($record->name, $zoneSuffix)) {
                throw new \InvalidArgumentException(
                    "Record name '$record->name' does not belong to zone '$this->name'",
                );
            }
        }

        // A managed apex CAA is stored as "@" and absolutized to the zone name.
        // The same CAA copied again under the apex FQDN is a second document with
        // the same rdata. Fastly Certainly treats those two identical issue
        // records as a conflict and refuses to issue. Publish the first copy only.
        $this->records = self::withoutDuplicateCaa($records);
    }

    /**
     * @param list<Record> $records
     * @return list<Record>
     */
    private static function withoutDuplicateCaa(array $records): array
    {
        $published = [];
        $seen = [];

        foreach ($records as $record) {
            if ($record->type !== Record::TYPE_CAA) {
                $published[] = $record;
                continue;
            }

            $key = json_encode([$record->name, $record->class, $record->rdata], JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $published[] = $record;
        }

        return $published;
    }

    /**
     * Check if the zone is authoritative for a given name
     */
    public function isAuthoritative(string $name): bool
    {
        // Check if there's a delegation (NS records) at this name
        // Root NS records are authoritative, not delegations
        if ($name === $this->name) {
            return true;
        }
        return array_all($this->records, fn (\Utopia\DNS\Message\Record $record): bool => $record->name !== $name || $record->type !== Record::TYPE_NS);
    }
}
