<?php

namespace Utopia\DNS\Zone;

use Utopia\DNS\Message;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Zone;

final readonly class Resolver
{
    /**
     * Resolve DNS Record
     * Performs a DNS lookup within the zone for the given query.
     *
     * Implements the DNS resolution algorithm following these steps:
     * 1. Validates the query has a question section
     * 2. Selects the best matching records for the query
     * 3. Handles exact matches or wildcard matches appropriately
     *
     * Algorithm reference:
     * - Flowchart: https://www.usenix.org/sites/default/files/styles/article_embedded/public/tree.png
     * - Pseudocode: https://www.usenix.org/sites/default/files/styles/article_embedded/public/code.png
     *
     * @param Message $query The DNS query message containing the question to resolve.
     * @param Zone $zone The DNS zone containing the records to resolve.
     * @return Message The DNS response message with appropriate records and response code.
     *                 Returns FORMERR if query lacks a question section.
     *                 Returns NXDOMAIN if no matching records are found.
     */
    public static function lookup(Message $query, Zone $zone): Message
    {
        $question = $query->questions[0] ?? null;
        if ($question === null) {
            return Message::response(
                header: $query->header,
                responseCode: Message::RCODE_FORMERR,
                authoritative: true,
            );
        }

        // Step 1: Select best matching records for the query
        $records = self::selectBestRecords($query, $zone);

        if ($records === []) {
            // SOA is stored separately; if querying SOA at the zone apex, return it
            if ($question->type === Record::TYPE_SOA && $question->name === $zone->name) {
                return self::soaApexResponse($query, $zone);
            }

            return Message::response(
                header: $query->header,
                responseCode: Message::RCODE_NXDOMAIN,
                questions: $query->questions,
                authority: [$zone->soa],
                authoritative: true,
            );
        }

        $rname = $records[0]->name;

        // Step 2: Check for exact match
        if ($rname === $question->name) {
            return self::handleExactMatch($records, $query, $zone);
        }

        // Step 3: Check if wildcard match
        if (self::isWildcardMatch($question->name, $rname)) {
            return self::handleWildcardMatch($records, $query, $zone);
        }

        // Should be unreachable
        return Message::response(
            header: $query->header,
            responseCode: Message::RCODE_NXDOMAIN,
            questions: $query->questions,
            authority: [$zone->soa],
            authoritative: true,
        );
    }

    /**
     * Select the best matching records for a query
     *
     * @return list<Record>
     */
    private static function selectBestRecords(Message $query, Zone $zone): array
    {
        $question = $query->questions[0] ?? throw new \RuntimeException('Not reachable');

        // First, try exact match

        $exactMatches = array_filter(
            $zone->records,
            fn(\Utopia\DNS\Message\Record $r): bool => $r->name === $question->name,
        );

        if ($exactMatches !== []) {
            return array_values($exactMatches);
        }

        // No exact match - try wildcard matching
        // Find the closest enclosing wildcard
        $wildcardRecord = self::findClosestWildcard($question->name, $zone);

        if ($wildcardRecord instanceof \Utopia\DNS\Message\Record) {
            // Return all records at the wildcard name
            return array_values(array_filter(
                $zone->records,
                fn(\Utopia\DNS\Message\Record $r): bool => $r->name === $wildcardRecord->name,
            ));
        }

        return [];
    }

    /**
     * Find the closest enclosing wildcard for a query name
     */
    private static function findClosestWildcard(string $questionName, Zone $zone): ?Record
    {
        // Generate potential wildcard names from most specific to least
        // For example, for "a.b.c.example.com":
        // - *.b.c.example.com
        // - *.c.example.com
        // - *.example.com

        $parts = explode('.', $questionName);
        $counter = \count($parts);

        for ($i = 1; $i < $counter; $i++) {
            $wildcardName = '*.' . implode('.', \array_slice($parts, $i));

            // Check if this wildcard exists in the zone
            foreach ($zone->records as $record) {
                if ($record->name === $wildcardName) {
                    return $record;
                }
            }
        }

        return null;
    }

    /**
     * Handle exact match case (E1, E2, E3, E4 paths)
     *
     * @param list<Record> $records
     */
    private static function handleExactMatch(array $records, Message $query, Zone $zone): Message
    {
        $question = $query->questions[0] ?? throw new \RuntimeException('Not reachable');

        // Check if zone is authoritative for this name
        $isAuthoritative = $zone->isAuthoritative($question->name);

        if ($isAuthoritative) {
            // SOA is stored separately in Zone; handle SOA queries at the zone apex
            if ($question->type === Record::TYPE_SOA && $question->name === $zone->name) {
                return self::soaApexResponse($query, $zone);
            }

            // Path E1: Exact match of type
            $exactTypeRecords = array_filter(
                $records,
                fn(\Utopia\DNS\Message\Record $r): bool => $r->type === $question->type,
            );

            if ($exactTypeRecords !== []) {
                // E1: Return exact type match (randomized for load balancing)
                return Message::response(
                    header: $query->header,
                    responseCode: Message::RCODE_NOERROR,
                    questions: $query->questions,
                    answers: self::randomizeRRSet(array_values($exactTypeRecords)),
                    authoritative: true,
                    recursionAvailable: false,
                );
            }

            // Check for CNAME
            $cnameRecords = array_filter($records, fn(\Utopia\DNS\Message\Record $r): bool => $r->type === Record::TYPE_CNAME);

            if ($cnameRecords !== []) {
                // E2: CNAME exists
                return Message::response(
                    header: $query->header,
                    responseCode: Message::RCODE_NOERROR,
                    questions: $query->questions,
                    answers: array_values($cnameRecords),
                    authoritative: true,
                    recursionAvailable: false,
                );
            }

            // E3: No matching type, no CNAME (NODATA)
            return Message::response(
                header: $query->header,
                responseCode: Message::RCODE_NOERROR,
                questions: $query->questions,
                authority: [$zone->soa],
                authoritative: true,
                recursionAvailable: false,
            );
        }
        // E4: Not authoritative - referral
        // Find NS records for delegation
        $nsRecords = array_filter($records, fn(\Utopia\DNS\Message\Record $r): bool => $r->type === Record::TYPE_NS);

        return Message::response(
            header: $query->header,
            responseCode: Message::RCODE_NOERROR,
            questions: $query->questions,
            authority: array_values($nsRecords),
            authoritative: false,
            recursionAvailable: false,
        );

    }

    /**
     * Build an authoritative SOA answer for the zone apex.
     */
    private static function soaApexResponse(Message $query, Zone $zone): Message
    {
        return Message::response(
            header: $query->header,
            responseCode: Message::RCODE_NOERROR,
            questions: $query->questions,
            answers: [$zone->soa],
            authoritative: true,
            recursionAvailable: false,
        );
    }

    /**
     * Randomize RRSet order for load balancing.
     *
     * Per RFC 2181 Section 5, the order of resource records within an RRSet
     * is not significant. By randomizing the order, we help distribute load
     * across multiple servers (e.g., multiple A records for the same name).
     *
     * @param list<Record> $records
     * @return list<Record>
     */
    private static function randomizeRRSet(array $records): array
    {
        if (\count($records) <= 1) {
            return $records;
        }

        // RFC 2181 Section 5: Order within RRSet is not significant
        // Randomization helps load balance across multiple A/AAAA records
        shuffle($records);
        return $records;
    }

    /**
     * Check if a query name matches a wildcard record name
     *
     * @param string $queryName The query name (e.g., "sub.example.com")
     * @param string $recordName The record name (e.g., "*.example.com")
     */
    private static function isWildcardMatch(string $queryName, string $recordName): bool
    {
        if (!str_starts_with($recordName, '*.')) {
            return false;
        }

        $wildcardSuffix = substr($recordName, 2); // Remove "*."
        $queryParts = explode('.', $queryName);

        // Build the suffix that should match
        $querySuffix = implode('.', \array_slice($queryParts, 1));

        return $querySuffix === $wildcardSuffix;
    }

    /**
     * Handle wildcard match case (W1, W2, W3 paths)
     *
     * @param list<Record> $records
     */
    private static function handleWildcardMatch(array $records, Message $query, Zone $zone): Message
    {
        $question = $query->questions[0] ?? throw new \RuntimeException('Not reachable');

        // W1: Exact type match in wildcard records
        $exactTypeRecords = array_filter(
            $records,
            fn(\Utopia\DNS\Message\Record $r): bool => $r->type === $question->type,
        );

        if ($exactTypeRecords !== []) {
            // Synthesize records with the query name (randomized for load balancing)
            $synthesizedRecords = array_map(
                fn($r) => $r->withName($question->name),
                $exactTypeRecords,
            );

            return Message::response(
                header: $query->header,
                responseCode: Message::RCODE_NOERROR,
                questions: $query->questions,
                answers: self::randomizeRRSet(array_values($synthesizedRecords)),
                authoritative: true,
                recursionAvailable: false,
            );
        }

        // Check for CNAME
        $cnameRecords = array_filter($records, fn(\Utopia\DNS\Message\Record $r): bool => $r->type === Record::TYPE_CNAME);

        if ($cnameRecords !== []) {
            // W2: CNAME in wildcard
            $synthesizedRecords = array_map(
                fn($r) => $r->withName($question->name),
                $cnameRecords,
            );

            return Message::response(
                header: $query->header,
                responseCode: Message::RCODE_NOERROR,
                questions: $query->questions,
                answers: array_values($synthesizedRecords),
                authoritative: true,
                recursionAvailable: false,
            );
        }

        // W3: No matching type in wildcard (NODATA)
        return Message::response(
            header: $query->header,
            responseCode: Message::RCODE_NOERROR,
            questions: $query->questions,
            authority: [$zone->soa],
            authoritative: true,
            recursionAvailable: false,
        );
    }
}
