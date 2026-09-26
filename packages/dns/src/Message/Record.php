<?php

namespace Utopia\DNS\Message;

use Utopia\DNS\Exception\Message\DecodingException;

/**
 * A DNS record.
 */
final readonly class Record
{
    public string $name;

    public const int TYPE_A = 1;
    public const int TYPE_NS = 2;
    public const int TYPE_MD = 3;
    public const int TYPE_MF = 4;
    public const int TYPE_CNAME = 5;
    public const int TYPE_SOA = 6;
    public const int TYPE_MB = 7;
    public const int TYPE_MG = 8;
    public const int TYPE_MR = 9;
    public const int TYPE_NULL = 10;
    public const int TYPE_WKS = 11;
    public const int TYPE_PTR = 12;
    public const int TYPE_HINFO = 13;
    public const int TYPE_MINFO = 14;
    public const int TYPE_MX = 15;
    public const int TYPE_TXT = 16;
    public const int TYPE_AAAA = 28;
    public const int TYPE_SRV = 33;
    public const int TYPE_CAA = 257;

    public const int CLASS_IN = 1;
    public const int CLASS_CS = 2;
    public const int CLASS_CH = 3;
    public const int CLASS_HS = 4;

    private const int IPV4_LEN = 4;
    private const int IPV6_LEN = 16;
    private const int MAX_PRIORITY = 65535;
    private const int MAX_WEIGHT = 65535;
    private const int MAX_PORT = 65535;
    private const int MAX_CAA_FLAGS = 255;
    private const int MAX_TXT_CHUNK = 255;

    /**
     * Map between textual record mnemonics and their numeric codes.
     *
     * @var array<string, int>
     */
    private const array TYPE_NAME_TO_CODE = [
        'A' => self::TYPE_A,
        'NS' => self::TYPE_NS,
        'MD' => self::TYPE_MD,
        'MF' => self::TYPE_MF,
        'CNAME' => self::TYPE_CNAME,
        'SOA' => self::TYPE_SOA,
        'MB' => self::TYPE_MB,
        'MG' => self::TYPE_MG,
        'MR' => self::TYPE_MR,
        'NULL' => self::TYPE_NULL,
        'WKS' => self::TYPE_WKS,
        'PTR' => self::TYPE_PTR,
        'HINFO' => self::TYPE_HINFO,
        'MINFO' => self::TYPE_MINFO,
        'MX' => self::TYPE_MX,
        'TXT' => self::TYPE_TXT,
        'AAAA' => self::TYPE_AAAA,
        'SRV' => self::TYPE_SRV,
        'CAA' => self::TYPE_CAA,
    ];

    /**
     * Reverse map between numeric codes and record mnemonics.
     *
     * @var array<int, string>
     */
    private const array TYPE_CODE_TO_NAME = [
        self::TYPE_A => 'A',
        self::TYPE_NS => 'NS',
        self::TYPE_MD => 'MD',
        self::TYPE_MF => 'MF',
        self::TYPE_CNAME => 'CNAME',
        self::TYPE_SOA => 'SOA',
        self::TYPE_MB => 'MB',
        self::TYPE_MG => 'MG',
        self::TYPE_MR => 'MR',
        self::TYPE_NULL => 'NULL',
        self::TYPE_WKS => 'WKS',
        self::TYPE_PTR => 'PTR',
        self::TYPE_HINFO => 'HINFO',
        self::TYPE_MINFO => 'MINFO',
        self::TYPE_MX => 'MX',
        self::TYPE_TXT => 'TXT',
        self::TYPE_AAAA => 'AAAA',
        self::TYPE_SRV => 'SRV',
        self::TYPE_CAA => 'CAA',
    ];

    /**
     * Creates a DNS record.
     *
     * @param string $name Domain names are absolute, lowercase and without a trailing dot.
     * @param int $type Record type. One of `Record::TYPE_*` constants.
     * @param int $class Record class. One of `Record::CLASS_*` constants. Defaults to `Record::CLASS_IN`.
     * @param int $ttl Time to live in seconds. Defaults to 0.
     * @param string $rdata Record data.
     * @param int|null $priority Priority. Used for MX and SRV records.
     * @param int|null $weight Weight. Used for SRV records.
     * @param int|null $port Port. Used for SRV records.
     */
    public function __construct(
        string $name,
        public int $type,
        public int $class = Record::CLASS_IN,
        public int $ttl = 0,
        public string $rdata = '',
        public ?int $priority = null,
        public ?int $weight = null,
        public ?int $port = null,
    ) {
        $this->name = trim(strtolower($name));
    }

    /**
     * Parse a DNS Resource Record from raw binary data.
     *
     * @param string $data   Full DNS packet data
     * @param int    &$offset Offset to start reading (updated after)
     * @return self
     */
    /**
     * @param-out int $offset
     */
    public static function decode(string $data, int &$offset): self
    {
        // 1. Parse NAME (may use compression)
        $name = Domain::decode($data, $offset);

        // 2. Read fixed-length fields
        $limit = \strlen($data);
        if ($offset + 10 > $limit) {
            throw new DecodingException('Truncated RR header');
        }
        $typeData = unpack('ntype', substr($data, $offset, 2));
        if (!\is_array($typeData) || !\array_key_exists('type', $typeData) || !\is_int($typeData['type'])) {
            throw new DecodingException('Failed to unpack record type');
        }
        $type = $typeData['type'];
        $offset += 2;

        $classData = unpack('nclass', substr($data, $offset, 2));
        if (!\is_array($classData) || !\array_key_exists('class', $classData) || !\is_int($classData['class'])) {
            throw new DecodingException('Failed to unpack record class');
        }
        $class = $classData['class'];
        $offset += 2;

        $ttlData = unpack('Nttl', substr($data, $offset, 4));
        if (!\is_array($ttlData) || !\array_key_exists('ttl', $ttlData) || !\is_int($ttlData['ttl'])) {
            throw new DecodingException('Failed to unpack record TTL');
        }
        $ttl = $ttlData['ttl'];
        $offset += 4;

        $rdLengthData = unpack('nlength', substr($data, $offset, 2));
        if (!\is_array($rdLengthData) || !\array_key_exists('length', $rdLengthData) || !\is_int($rdLengthData['length'])) {
            throw new DecodingException('Failed to unpack record length');
        }
        $rdlength = $rdLengthData['length'];
        $offset += 2;

        if ($offset + $rdlength > $limit) {
            throw new DecodingException('RDATA exceeds packet bounds');
        }
        $rdataRaw = substr($data, $offset, $rdlength);
        $offset += $rdlength;

        // 3. Interpret RDATA based on type
        $rdata = '';
        $priority = $weight = $port = null;

        switch ($type) {
            case Record::TYPE_A:
                if (\strlen($rdataRaw) !== Record::IPV4_LEN) {
                    throw new DecodingException('Invalid IPv4 address length');
                }
                $decoded = inet_ntop($rdataRaw);
                if ($decoded === false) {
                    throw new DecodingException('Invalid IPv4 address payload');
                }
                $rdata = $decoded;
                break;

            case Record::TYPE_AAAA:
                if (\strlen($rdataRaw) !== Record::IPV6_LEN) {
                    throw new DecodingException('Invalid IPv6 address length');
                }
                $decoded = inet_ntop($rdataRaw);
                if ($decoded === false) {
                    throw new DecodingException('Invalid IPv6 address payload');
                }
                $rdata = $decoded;
                break;

            case Record::TYPE_CNAME:
            case Record::TYPE_NS:
            case Record::TYPE_PTR:
                $tempOffset = $offset - $rdlength;
                $rdata = Domain::decode($data, $tempOffset);
                break;

            case Record::TYPE_MX:
                if (\strlen($rdataRaw) < 3) { // 2 bytes preference + at least 1 for name
                    throw new DecodingException('Invalid MX RDATA length: ' . \strlen($rdataRaw));
                }
                $priorityData = unpack('npriority', substr($rdataRaw, 0, 2));
                if (!\is_array($priorityData) || !\array_key_exists('priority', $priorityData) || !\is_int($priorityData['priority'])) {
                    throw new DecodingException('Failed to unpack MX priority');
                }
                $priority = $priorityData['priority'];
                $tempOffset = $offset - $rdlength + 2;
                $rdata = Domain::decode($data, $tempOffset);
                break;

            case Record::TYPE_SRV:
                if (\strlen($rdataRaw) < 7) { // 6 bytes (pri,weight,port) + at least 1 for name
                    throw new DecodingException('Invalid SRV RDATA length: ' . \strlen($rdataRaw));
                }
                $priorityData = unpack('npriority', substr($rdataRaw, 0, 2));
                $weightData = unpack('nweight', substr($rdataRaw, 2, 2));
                $portData = unpack('nport', substr($rdataRaw, 4, 2));
                if (!\is_array($priorityData) || !\array_key_exists('priority', $priorityData) || !\is_int($priorityData['priority'])) {
                    throw new DecodingException('Failed to unpack SRV priority');
                }
                if (!\is_array($weightData) || !\array_key_exists('weight', $weightData) || !\is_int($weightData['weight'])) {
                    throw new DecodingException('Failed to unpack SRV weight');
                }
                if (!\is_array($portData) || !\array_key_exists('port', $portData) || !\is_int($portData['port'])) {
                    throw new DecodingException('Failed to unpack SRV port');
                }
                $priority = $priorityData['priority'];
                $weight = $weightData['weight'];
                $port = $portData['port'];
                $tempOffset = $offset - $rdlength + 6;
                $rdata = Domain::decode($data, $tempOffset);
                break;

            case Record::TYPE_SOA:
                $tempOffset = $offset - $rdlength;
                $mname = Domain::decode($data, $tempOffset);
                $rname = Domain::decode($data, $tempOffset);

                $timingData = substr($data, $tempOffset, 20);
                if (\strlen($timingData) < 20) {
                    throw new DecodingException('Invalid SOA record length');
                }

                $fields = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminimum', $timingData);
                if (
                    !\is_array($fields)
                    || !isset($fields['serial'], $fields['refresh'], $fields['retry'], $fields['expire'], $fields['minimum'])
                    || !\is_int($fields['serial'])
                    || !\is_int($fields['refresh'])
                    || !\is_int($fields['retry'])
                    || !\is_int($fields['expire'])
                    || !\is_int($fields['minimum'])
                ) {
                    throw new DecodingException('Unable to unpack SOA timings');
                }

                // Convert signed to unsigned for serial
                $serial = $fields['serial'];
                $refresh = $fields['refresh'];
                $retry = $fields['retry'];
                $expire = $fields['expire'];
                $minimum = $fields['minimum'];
                if ($serial < 0) {
                    $serial += 4294967296;
                }

                $rdata = \sprintf(
                    '%s %s %u %u %u %u %u',
                    $mname,
                    $rname,
                    $serial,
                    $refresh,
                    $retry,
                    $expire,
                    $minimum,
                );
                break;

            case Record::TYPE_TXT:
                if ($rdlength < 1) {
                    throw new DecodingException('Invalid TXT RDATA length: 0');
                }

                // Handle multiple character strings
                $chunks = [];
                $pos = 0;
                while ($pos < $rdlength) {
                    $len = \ord($rdataRaw[$pos]);
                    if ($pos + 1 + $len > $rdlength) {
                        throw new DecodingException('TXT chunk length exceeds RDATA size');
                    }
                    $chunks[] = substr($rdataRaw, $pos + 1, $len);
                    $pos += $len + 1;
                }
                $rdata = implode('', $chunks);
                break;

            case Record::TYPE_CAA:
                if ($rdlength < 2) {
                    throw new DecodingException('Invalid CAA record length');
                }

                $flags = \ord($rdataRaw[0]);
                $tagLength = \ord($rdataRaw[1]);
                if ($tagLength > \strlen($rdataRaw) - 2) {
                    throw new DecodingException('Invalid CAA tag length');
                }

                $tag = substr($rdataRaw, 2, $tagLength);
                $value = substr($rdataRaw, 2 + $tagLength);
                $rdata = \sprintf('%d %s "%s"', $flags, $tag, $value);
                break;

            default:
                $rdata = bin2hex($rdataRaw);
                break;
        }

        return new self($name, $type, $class, $ttl, $rdata, $priority, $weight, $port);
    }

    public static function typeNameToCode(string $name): ?int
    {
        return self::TYPE_NAME_TO_CODE[strtoupper($name)] ?? null;
    }

    public static function typeCodeToName(int $code): ?string
    {
        return self::TYPE_CODE_TO_NAME[$code] ?? null;
    }

    /**
     * Create a new record with the same data but a different name.
     *
     * @param string $name New name for the record
     * @return self New record instance with the updated name
     */
    public function withName(string $name): self
    {
        return new self($name, $this->type, $this->class, $this->ttl, $this->rdata, $this->priority, $this->weight, $this->port);
    }

    /**
     * Encode this record into DNS packet format.
     *
     * @return string Binary representation of the record
     */
    public function encode(): string
    {
        $data = '';
        // 1. Encode NAME
        $data .= Domain::encode($this->name);
        // 2. TYPE (2 bytes)
        $data .= pack('n', $this->type);
        // 3. CLASS (2 bytes)
        $data .= pack('n', $this->class);
        // 4. TTL (4 bytes)
        $data .= pack('N', $this->ttl);
        // 5. RDLENGTH + RDATA
        $rdata = $this->encodeRdata();
        $data .= pack('n', \strlen($rdata));
        return $data . $rdata;
    }

    /**
     * Validate RDATA for this record type without encoding the full record.
     */
    public function validateRdata(): void
    {
        $this->encodeRdata();
    }

    /**
     * Encode RDATA based on record type.
     */
    private function encodeRdata(): string
    {
        switch ($this->type) {
            case self::TYPE_A:
                $packed = inet_pton($this->rdata);
                if ($packed === false || \strlen($packed) !== self::IPV4_LEN) {
                    throw new \InvalidArgumentException("Invalid IPv4 address: $this->rdata");
                }

                return $packed;

            case self::TYPE_AAAA:
                $packed = inet_pton($this->rdata);
                if ($packed === false || \strlen($packed) !== self::IPV6_LEN) {
                    throw new \InvalidArgumentException("Invalid IPv6 address: $this->rdata");
                }

                return $packed;

            case self::TYPE_CNAME:
            case self::TYPE_NS:
            case self::TYPE_PTR:
                return Domain::encode($this->rdata);

            case self::TYPE_MX:
                $priority = $this->priority ?? 0;
                if ($priority < 0 || $priority > self::MAX_PRIORITY) {
                    throw new \InvalidArgumentException(
                        \sprintf('MX priority must be between 0 and %d, got %d', self::MAX_PRIORITY, $priority),
                    );
                }

                return pack('n', $priority) . Domain::encode($this->rdata);

            case self::TYPE_SRV:
                $priority = $this->priority ?? 0;
                $weight = $this->weight ?? 0;
                $port = $this->port ?? 0;

                if ($priority < 0 || $priority > self::MAX_PRIORITY) {
                    throw new \InvalidArgumentException(
                        \sprintf('SRV priority must be between 0 and %d, got %d', self::MAX_PRIORITY, $priority),
                    );
                }
                if ($weight < 0 || $weight > self::MAX_WEIGHT) {
                    throw new \InvalidArgumentException(
                        \sprintf('SRV weight must be between 0 and %d, got %d', self::MAX_WEIGHT, $weight),
                    );
                }
                if ($port < 0 || $port > self::MAX_PORT) {
                    throw new \InvalidArgumentException(
                        \sprintf('SRV port must be between 0 and %d, got %d', self::MAX_PORT, $port),
                    );
                }

                return pack('nnn', $priority, $weight, $port)
                    . Domain::encode($this->rdata);

            case self::TYPE_TXT:
                // Split rdata into chunks of up to 255 bytes each per RFC 1035
                $rdata = $this->rdata;
                $totalLen = \strlen($rdata);

                // Handle empty rdata: emit a single zero-length character-string
                if ($totalLen === 0) {
                    return \chr(0);
                }

                $encoded = '';
                $pos = 0;

                while ($pos < $totalLen) {
                    $chunkLen = min(self::MAX_TXT_CHUNK, $totalLen - $pos);
                    $chunk = substr($rdata, $pos, $chunkLen);
                    $encoded .= \chr($chunkLen) . $chunk;
                    $pos += $chunkLen;
                }

                return $encoded;

            case self::TYPE_CAA:
                return $this->encodeCaaRdata();

            case self::TYPE_SOA:
                return $this->encodeSoaRdata();

            default:
                // Assume hex-encoded for unknown types
                $binary = hex2bin($this->rdata);
                if ($binary === false) {
                    throw new \InvalidArgumentException('Invalid hexadecimal payload for record type ' . $this->type);
                }

                return $binary;
        }
    }

    private function encodeSoaRdata(): string
    {
        $input = trim($this->rdata);
        if ($input === '') {
            throw new \InvalidArgumentException('SOA RDATA cannot be empty');
        }

        $tokens = preg_split('/\s+/', $input);
        if ($tokens === false) {
            throw new \InvalidArgumentException('Unable to parse SOA RDATA');
        }

        $parts = [];
        foreach ($tokens as $token) {
            $clean = trim($token);
            if ($clean === '') {
                continue;
            }
            if ($clean === '(') {
                continue;
            }
            if ($clean === ')') {
                continue;
            }
            $parts[] = $clean;
        }

        if (\count($parts) !== 7) {
            throw new \InvalidArgumentException(
                'SOA RDATA must contain MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE and MINIMUM fields',
            );
        }

        [$mname, $rname, $serial, $refresh, $retry, $expire, $minimum] = $parts;

        $numbers = [];
        foreach ([$serial, $refresh, $retry, $expire, $minimum] as $value) {
            if (!preg_match('/^\d+$/', $value)) {
                throw new \InvalidArgumentException('SOA timing fields must be unsigned integers');
            }

            $number = (int) $value;
            if ($number < 0 || $number > 0xFFFFFFFF) {
                throw new \InvalidArgumentException('SOA timing field out of range: ' . $value);
            }
            $numbers[] = $number;
        }

        [$serialNum, $refreshNum, $retryNum, $expireNum, $minimumNum] = $numbers;

        return Domain::encode($mname)
            . $this->encodeSoaRname($rname)
            . pack('NNNNN', $serialNum, $refreshNum, $retryNum, $expireNum, $minimumNum);
    }

    private function encodeSoaRname(string $rname): string
    {
        if (!str_contains($rname, '@')) {
            return Domain::encode($rname);
        }

        if (substr_count($rname, '@') > 1) {
            throw new \InvalidArgumentException(
                'SOA RNAME email must contain exactly one @ separator',
            );
        }

        [$localPart, $domain] = explode('@', $rname, 2);

        if ($localPart === '' || $domain === '') {
            throw new \InvalidArgumentException(
                'SOA RNAME email must have non-empty local part and domain',
            );
        }

        $localLength = \strlen($localPart);
        if ($localLength > Domain::MAX_LABEL_LEN) {
            throw new \InvalidArgumentException("Label too long: $localPart");
        }

        $encoded = \chr($localLength) . $localPart . Domain::encode($domain);
        if (\strlen($encoded) > Domain::MAX_DOMAIN_NAME_LEN) {
            throw new \InvalidArgumentException(
                'Encoded domain exceeds maximum length of ' . Domain::MAX_DOMAIN_NAME_LEN . ' bytes',
            );
        }

        return $encoded;
    }

    private function encodeCaaRdata(): string
    {
        $input = trim($this->rdata);
        if ($input === '') {
            throw new \InvalidArgumentException('CAA RDATA cannot be empty');
        }

        $pattern = '/^(?:(\d+)\s+)?([A-Za-z0-9-]+)\s+"((?:\\\\.|[^"])*)"$/';
        if (!preg_match($pattern, $input, $matches)) {
            throw new \InvalidArgumentException("Invalid CAA RDATA format: $this->rdata");
        }

        $flags = (int) $matches[1];
        if ($flags < 0 || $flags > self::MAX_CAA_FLAGS) {
            throw new \InvalidArgumentException(
                \sprintf('CAA flags must be between 0 and %d, got %d', self::MAX_CAA_FLAGS, $flags),
            );
        }

        $tag = $matches[2];
        if (\strlen($tag) > 255) {
            throw new \InvalidArgumentException('CAA tag exceeds 255 bytes');
        }

        $value = stripcslashes($matches[3]);

        return \chr($flags) . \chr(\strlen($tag)) . $tag . $value;
    }
}
