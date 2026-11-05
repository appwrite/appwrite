<?php

namespace Utopia\DNS\Zone;

use InvalidArgumentException;
use Utopia\DNS\Exception\Zone\ImportException;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Zone;

/**
 * Import/export DNS zone files (RFC 1035 master file format).
 * https://datatracker.ietf.org/doc/html/rfc1035
 */
final readonly class File
{
    /**
     * Map of supported RR classes keyed by their mnemonic.
     *
     * @var array<string,int>
     */
    private const array CLASS_MAP = [
        'IN' => Record::CLASS_IN,
        'CS' => Record::CLASS_CS,
        'CH' => Record::CLASS_CH,
        'HS' => Record::CLASS_HS,
    ];

    /**
     * Import a zone from RFC 1035 master file format string.
     *
     * @param string      $content       Zone file content
     * @param string|null $defaultOrigin Default origin if $ORIGIN is not specified
     * @param int         $defaultTTL    Default TTL if not specified (default: 3600)
     *
     * @throws ImportException
     */
    public static function import(string $content, ?string $defaultOrigin = null, int $defaultTTL = 3600): Zone
    {
        $normalizedLines = self::preprocess($content); // array<array{line:string,num:int}>
        $records = [];
        $soa = null;

        $origin = null;
        $zoneName = null;
        $zoneNameFromDefault = false;

        if ($defaultOrigin !== null) {
            $origin = self::canonicalizeName($defaultOrigin);
            if ($origin === null) {
                throw new ImportException($content, 'Default origin must not be empty');
            }
            $zoneName = $origin;
            $zoneNameFromDefault = true;
        }

        $lastOwner = null;
        $lastTTL   = $defaultTTL;
        $lastClass = Record::CLASS_IN;

        foreach ($normalizedLines as ['line' => $line, 'num' => $num]) {
            if ($line === '') {
                continue;
            }

            // Directives
            try {
                $directive = self::handleDirectives($line, $origin, $lastTTL);
            } catch (InvalidArgumentException $e) {
                throw new ImportException($content, $e->getMessage(), previous: $e);
            }

            if ($directive !== null) {
                if ($directive === 'origin') {
                    if ($origin === null) {
                        throw new ImportException($content, '$ORIGIN directive must not be empty');
                    }
                    if ($zoneName === null || $zoneNameFromDefault) {
                        $zoneName = $origin;
                        $zoneNameFromDefault = false;
                    }
                }
                continue;
            }

            if ($line[0] === '$') {
                // Unknown directive – skip gracefully
                continue;
            }

            $ownerOmitted = $line[0] === ' ' || $line[0] === "\t";
            $line = ltrim($line);

            try {
                $rr = self::parseResourceRecord($line, $origin, $lastOwner, $lastTTL, $lastClass, $ownerOmitted, $num);
            } catch (InvalidArgumentException $e) {
                throw new ImportException($content, $e->getMessage(), previous: $e);
            }

            // Update state from parsed RR
            $lastOwner = $rr['name'];
            $lastTTL   = $rr['ttl'];
            $lastClass = $rr['class'];

            $record = new Record(
                name: $rr['name'],
                type: $rr['type'],
                class: $rr['class'],
                ttl: $rr['ttl'],
                rdata: $rr['rdata'],
                priority: $rr['priority'],
                weight: $rr['weight'],
                port: $rr['port'],
            );

            if ($rr['type'] === Record::TYPE_SOA) {
                if ($soa instanceof \Utopia\DNS\Message\Record) {
                    throw new ImportException($content, "Multiple SOA records found (line $num).");
                }
                $soa = $record;
                continue;
            }

            $records[] = $record;
        }

        if (!$soa instanceof \Utopia\DNS\Message\Record) {
            throw new ImportException($content, 'No SOA record found in zone file');
        }

        if ($zoneName === null) {
            throw new ImportException($content, 'Unable to determine zone name: provide an $ORIGIN directive or defaultOrigin.');
        }

        return new Zone($zoneName, $records, $soa);
    }

    /**
     * Export a zone to RFC 1035 master file format string.
     */
    public static function export(Zone $zone, bool $includeComments = true): string
    {
        $out = [];

        if ($includeComments) {
            $out[] = '; Zone file for ' . $zone->name;
            $out[] = '; Generated on ' . date('Y-m-d H:i:s');
            $out[] = '';
        }

        $out[] = '$ORIGIN ' . self::ensureTrailingDot($zone->name);
        $out[] = '$TTL ' . $zone->soa->ttl;
        $out[] = '';

        if ($includeComments) {
            $out[] = '; SOA Record';
        }
        $out[] = self::formatResourceRecord($zone->soa, $zone->name);
        $out[] = '';

        $recordsByType = self::groupRecordsByType($zone->records);

        // Prefer common order
        $preferred = [
            Record::TYPE_NS,
            Record::TYPE_A,
            Record::TYPE_AAAA,
            Record::TYPE_MX,
            Record::TYPE_CNAME,
            Record::TYPE_TXT,
        ];

        foreach ($preferred as $type) {
            if (!isset($recordsByType[$type])) {
                continue;
            }
            if ($includeComments) {
                $out[] = '; ' . self::getTypeString($type) . ' Records';
            }
            foreach ($recordsByType[$type] as $r) {
                $out[] = self::formatResourceRecord($r, $zone->name);
            }
            $out[] = '';
            unset($recordsByType[$type]);
        }

        // Emit the rest
        foreach ($recordsByType as $type => $list) {
            if ($includeComments) {
                $out[] = '; ' . self::getTypeString((int) $type) . ' Records';
            }
            foreach ($list as $r) {
                $out[] = self::formatResourceRecord($r, $zone->name);
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * Preprocess content: strip comments, handle multi-line parentheses, preserve original line numbers for error context.
     *
     * @return array<int,array{line:string,num:int}>
     */
    private static function preprocess(string $content): array
    {
        $rawLines = preg_split('/\R/', $content) ?: [];
        $out = [];

        $acc = '';
        $startNum = 0;
        $inParen = false;

        foreach ($rawLines as $i => $raw) {
            $lineNum = $i + 1;
            $original = self::removeComment($raw);
            if (trim($original) === '') {
                if ($inParen) {
                    continue;
                }
                $out[] = ['line' => '', 'num' => $lineNum];
                continue;
            }

            $line = rtrim($original);

            // If the line only held whitespace, treat it as blank (handled above)
            if ($line === '') {
                if ($inParen) {
                    continue;
                }
                $out[] = ['line' => '', 'num' => $lineNum];
                continue;
            }

            // Parentheses continuation
            $opens  = substr_count($line, '(');
            $closes = substr_count($line, ')');

            if (!$inParen && $opens > $closes) {
                $inParen = true;
                $acc = $line;
                $startNum = $lineNum;
                continue;
            }

            if ($inParen) {
                $acc .= ' ' . $line;
                $opens  += substr_count($acc, '(');
                $closes += substr_count($acc, ')');
                if ($opens <= $closes) {
                    $inParen = false;
                    $merged = trim(str_replace(['(', ')'], '', $acc));
                    $out[] = ['line' => $merged, 'num' => $startNum];
                    $acc = '';
                }
                continue;
            }

            $out[] = ['line' => $line, 'num' => $lineNum];
        }

        if ($inParen) {
            // Unbalanced parentheses: still emit what we have to surface an error later
            $merged = trim(str_replace(['(', ')'], '', $acc));
            $out[] = ['line' => $merged, 'num' => $startNum];
        }

        return $out;
    }

    /**
     * Handle $ORIGIN / $TTL directives.
     *
     * @param-out string|null $origin
     * @param-out int         $lastTTL
     * @return 'origin'|'ttl'|null
     */
    private static function handleDirectives(string $line, ?string &$origin, int &$lastTTL): ?string
    {
        if (preg_match('/^\s*\$ORIGIN\s+(\S+)\s*$/i', $line, $m) === 1) {
            $origin = self::canonicalizeName($m[1]);
            return 'origin';
        }

        if (preg_match('/^\s*\$TTL\s+(\d+)\s*$/i', $line, $m) === 1) {
            $lastTTL = (int) $m[1];
            return 'ttl';
        }

        if (preg_match('/^\s*\$INCLUDE\b/i', $line) === 1) {
            throw new InvalidArgumentException('$INCLUDE directive is not supported');
        }

        return null;
    }

    /**
     * @return array{
     *   name:string, ttl:int, class:int, type:int, rdata:string,
     *   priority:int|null, weight:int|null, port:int|null
     * }
     */
    private static function parseResourceRecord(
        string $line,
        ?string $origin,
        ?string $lastOwner,
        int $lastTTL,
        int $lastClass,
        bool $ownerOmitted,
        int $lineNum,
    ): array {
        $tokens = self::splitWhitespace($line);
        if ($tokens === []) {
            throw new InvalidArgumentException("Empty resource record (line $lineNum).");
        }

        $i = 0;

        // Owner
        if ($ownerOmitted) {
            $name = $lastOwner;
            if ($name === null) {
                throw new InvalidArgumentException("Owner omitted but no previous owner available (line $lineNum).");
            }
        } elseif ($tokens[0] === '@') {
            $name = $origin;
            $i++;
        } else {
            $name = self::absolutizeDomainName($tokens[$i], $origin);
            $i++;
        }

        if ($name === null) {
            throw new InvalidArgumentException("Record is missing an owner name (line $lineNum).");
        }
        $name = self::canonicalizeName($name);
        if ($name === null) {
            throw new InvalidArgumentException("Owner name is invalid (line $lineNum).");
        }

        // Optional TTL / CLASS (any order)
        $ttl   = $lastTTL;
        $class = $lastClass;

        while ($i < \count($tokens) - 1) {
            $t = $tokens[$i];

            if (ctype_digit($t)) {
                $ttl = (int) $t;
                $i++;
                continue;
            }

            $upper = strtoupper($t);
            if (isset(self::CLASS_MAP[$upper])) {
                $class = self::CLASS_MAP[$upper];
                $i++;
                continue;
            }

            break;
        }

        if ($i >= \count($tokens)) {
            throw new InvalidArgumentException("Missing record type (line $lineNum).");
        }

        $typeString = strtoupper($tokens[$i]);
        $type = Record::typeNameToCode($typeString);
        if ($type === null) {
            throw new InvalidArgumentException("Invalid record type '$typeString' (line $lineNum).");
        }

        $i++;

        $rdataTokens = \array_slice($tokens, $i);
        if ($rdataTokens === []) {
            throw new InvalidArgumentException("Record '$typeString' has no RDATA (line $lineNum).");
        }

        $r = self::parseRdata($type, $rdataTokens, $origin, $lineNum);

        return [
            'name'     => $name,
            'ttl'      => $ttl,
            'class'    => $class,
            'type'     => $type,
            'rdata'    => $r['rdata'],
            'priority' => $r['priority'],
            'weight'   => $r['weight'],
            'port'     => $r['port'],
        ];
    }

    /**
     * @param array<int,string> $tokens
     * @return array{rdata:string, priority:int|null, weight:int|null, port:int|null}
     */
    private static function parseRdata(int $type, array $tokens, ?string $origin, int $lineNum): array
    {
        $priority = $weight = $port = null;

        switch ($type) {
            case Record::TYPE_A:
            case Record::TYPE_AAAA:
                return ['rdata' => $tokens[0], 'priority' => null, 'weight' => null, 'port' => null];

            case Record::TYPE_NS:
            case Record::TYPE_CNAME:
            case Record::TYPE_PTR:
                $name = self::absolutizeDomainName($tokens[0], $origin);
                if ($name === null) {
                    throw new InvalidArgumentException("Relative domain name requires an origin (line $lineNum).");
                }
                return ['rdata' => $name, 'priority' => null, 'weight' => null, 'port' => null];

            case Record::TYPE_MX:
                if (\count($tokens) < 2 || !ctype_digit($tokens[0])) {
                    throw new InvalidArgumentException("MX requires numeric priority and exchange (line $lineNum).");
                }
                $priority = (int) $tokens[0];
                $exchange = self::absolutizeDomainName($tokens[1], $origin);
                if ($exchange === null) {
                    throw new InvalidArgumentException("MX exchange requires an origin (line $lineNum).");
                }
                return ['rdata' => $exchange, 'priority' => $priority, 'weight' => null, 'port' => null];

            case Record::TYPE_SRV:
                if (\count($tokens) < 4 || !ctype_digit($tokens[0]) || !ctype_digit($tokens[1]) || !ctype_digit($tokens[2])) {
                    throw new InvalidArgumentException("SRV requires priority, weight, port, target (line $lineNum).");
                }
                $priority = (int) $tokens[0];
                $weight   = (int) $tokens[1];
                $port     = (int) $tokens[2];
                $target   = self::absolutizeDomainName($tokens[3], $origin);
                if ($target === null) {
                    throw new InvalidArgumentException("SRV target requires an origin (line $lineNum).");
                }
                return ['rdata' => $target, 'priority' => $priority, 'weight' => $weight, 'port' => $port];

            case Record::TYPE_SOA:
                if (\count($tokens) < 7) {
                    throw new InvalidArgumentException("SOA requires MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE, MINIMUM (line $lineNum).");
                }
                $mname = self::absolutizeDomainName($tokens[0], $origin);
                $rname = self::absolutizeDomainName($tokens[1], $origin);
                if ($mname === null || $rname === null) {
                    throw new InvalidArgumentException("SOA requires origin for MNAME and RNAME (line $lineNum).");
                }
                $rdata = implode(' ', [$mname, $rname, $tokens[2], $tokens[3], $tokens[4], $tokens[5], $tokens[6]]);
                return ['rdata' => $rdata, 'priority' => null, 'weight' => null, 'port' => null];

            case Record::TYPE_TXT:
                $segments = [];
                foreach ($tokens as $t) {
                    $t = trim($t);
                    if ($t === '') {
                        continue;
                    }
                    if ($t[0] === '"' && str_ends_with($t, '"')) {
                        $segments[] = self::decodeTxtSegment(substr($t, 1, -1));
                        continue;
                    }
                    $segments[] = self::decodeTxtSegment($t);
                }
                return ['rdata' => implode('', $segments), 'priority' => null, 'weight' => null, 'port' => null];

            case Record::TYPE_CAA:
                if (\count($tokens) < 3 || !ctype_digit($tokens[0])) {
                    throw new InvalidArgumentException("CAA requires flag, tag, and quoted value (line $lineNum).");
                }
                $flag = (int) $tokens[0];
                if ($flag < 0 || $flag > 255) {
                    throw new InvalidArgumentException("CAA flag must be between 0 and 255 (line $lineNum).");
                }
                $valueToken = $tokens[2];
                if ($valueToken === '' || $valueToken[0] !== '"' || !str_ends_with($valueToken, '"')) {
                    throw new InvalidArgumentException("CAA value must be quoted (line $lineNum).");
                }
                return ['rdata' => implode(' ', $tokens), 'priority' => null, 'weight' => null, 'port' => null];

            default:
                return ['rdata' => implode(' ', $tokens), 'priority' => null, 'weight' => null, 'port' => null];
        }
    }

    private static function formatResourceRecord(Record $record, string $origin): string
    {
        $name = self::relativizeDomainName($record->name, $origin);

        if ($record->type === Record::TYPE_SOA) {
            $parts = explode(' ', $record->rdata);
            if (\count($parts) >= 7) {
                return \sprintf(
                    "%s\t%d\t%s\t%s\t%s %s (\n\t\t\t\t%s\t; serial\n\t\t\t\t%s\t; refresh\n\t\t\t\t%s\t; retry\n\t\t\t\t%s\t; expire\n\t\t\t\t%s )\t; minimum",
                    $name,
                    $record->ttl,
                    self::getClassString($record->class),
                    self::getTypeString($record->type),
                    self::relativizeDomainName($parts[0], $origin),
                    self::relativizeDomainName($parts[1], $origin),
                    $parts[2],
                    $parts[3],
                    $parts[4],
                    $parts[5],
                    $parts[6],
                );
            }
        }

        $rdata = self::formatRdata(
            $record->type,
            $record->rdata,
            $origin,
            $record->priority,
            $record->weight,
            $record->port,
        );

        return \sprintf(
            "%s\t%d\t%s\t%s\t%s",
            $name,
            $record->ttl,
            self::getClassString($record->class),
            self::getTypeString($record->type),
            $rdata,
        );
    }

    private static function formatRdata(
        int $type,
        string $rdata,
        string $origin,
        ?int $priority = null,
        ?int $weight = null,
        ?int $port = null,
    ): string {
        switch ($type) {
            case Record::TYPE_NS:
            case Record::TYPE_CNAME:
            case Record::TYPE_PTR:
                return self::relativizeDomainName($rdata, $origin);

            case Record::TYPE_MX:
                $pri = $priority ?? 0;
                return $pri . ' ' . self::relativizeDomainName($rdata, $origin);

            case Record::TYPE_SRV:
                $pri = $priority ?? 0;
                $wgt = $weight ?? 0;
                $prt = $port ?? 0;
                return \sprintf('%d %d %d %s', $pri, $wgt, $prt, self::relativizeDomainName($rdata, $origin));

            case Record::TYPE_TXT:
                return '"' . addcslashes($rdata, '"\\') . '"';

            case Record::TYPE_CAA:

            default:
                return $rdata;
        }
    }

    private static function ensureTrailingDot(string $name): string
    {
        return rtrim($name, '.') . '.';
    }

    private static function absolutizeDomainName(string $name, ?string $origin): ?string
    {
        $name = trim($name);
        if ($name === '' || $name === '@') {
            return $origin;
        }

        if (str_ends_with($name, '.')) {
            return self::canonicalizeName($name);
        }

        $origin = self::canonicalizeName($origin);
        if ($origin === null || $origin === '.') {
            return self::canonicalizeName($name);
        }

        return self::canonicalizeName($name . '.' . $origin);
    }

    private static function relativizeDomainName(string $name, string $origin): string
    {
        $name   = self::ensureTrailingDot($name);
        $origin = self::ensureTrailingDot($origin);

        if ($name === $origin) {
            return '@';
        }

        if (str_ends_with($name, $origin)) {
            return rtrim(substr($name, 0, -\strlen($origin)), '.');
        }

        return $name;
    }

    private static function getTypeString(int $type): string
    {
        return Record::typeCodeToName($type) ?? 'TYPE' . $type;
    }

    private static function getClassString(int $class): string
    {
        $map = [
            Record::CLASS_IN => 'IN',
            Record::CLASS_CS => 'CS',
            Record::CLASS_CH => 'CH',
            Record::CLASS_HS => 'HS',
        ];
        return $map[$class] ?? 'CLASS' . $class;
    }

    /**
     * Split a string by whitespace while respecting quotes and escapes.
     *
     * @return array<int,string>
     */
    private static function splitWhitespace(string $str): array
    {
        $tokens = [];
        $current = '';
        $len = \strlen($str);
        $inQuotes = false;
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];

            if ($escaped) {
                $current .= $ch;
                $escaped = false;
                continue;
            }

            if ($ch === '\\') {
                $current .= $ch;
                $escaped = true;
                continue;
            }

            if ($inQuotes) {
                $current .= $ch;
                if ($ch === $quote) {
                    $inQuotes = false;
                    $quote = null;
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inQuotes = true;
                $quote = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === ' ' || $ch === "\t") {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                continue;
            }

            $current .= $ch;
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Canonicalize a domain name by trimming whitespace, removing the trailing dot and lowercasing it.
     *
     * @return string|null '.' for root, or null if empty input
     */
    private static function canonicalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $trimmed = rtrim($name, '.');
        if ($trimmed === '') {
            return '.';
        }

        return strtolower($trimmed);
    }

    private static function decodeTxtSegment(string $value): string
    {
        $decoded = '';
        $length = \strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char !== '\\') {
                $decoded .= $char;
                continue;
            }

            $i++;
            if ($i >= $length) {
                $decoded .= '\\';
                break;
            }

            $next = $value[$i];

            if (ctype_digit($next)) {
                $digits = $next;
                $count = 1;
                while ($count < 3 && $i + 1 < $length && ctype_digit($value[$i + 1])) {
                    $digits .= $value[++$i];
                    $count++;
                }
                $decoded .= \chr((int) $digits);
                continue;
            }

            $decoded .= $next;
        }

        return $decoded;
    }

    /**
     * Remove comments starting with ';' (not inside quotes and not escaped).
     */
    private static function removeComment(string $line): string
    {
        $result = '';
        $len = \strlen($line);
        $escaped = false;
        $inQuotes = false;
        $quote = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($escaped) {
                $result .= $ch;
                $escaped = false;
                continue;
            }

            if ($ch === '\\') {
                $result .= $ch;
                $escaped = true;
                continue;
            }

            if ($inQuotes) {
                $result .= $ch;
                if ($ch === $quote) {
                    $inQuotes = false;
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inQuotes = true;
                $quote = $ch;
                $result .= $ch;
                continue;
            }

            if ($ch === ';') {
                break; // comment begins
            }

            $result .= $ch;
        }

        return $result;
    }

    /**
     * @param array<int,Record> $records
     * @return array<int,array<int,Record>>
     */
    private static function groupRecordsByType(array $records): array
    {
        $byType = [];
        foreach ($records as $r) {
            $byType[$r->type][] = $r;
        }
        return $byType;
    }
}
