<?php

namespace Utopia\DNS;

use Utopia\DNS\Exception\Message\DecodingException;
use Utopia\DNS\Exception\Message\PartialDecodingException;
use Utopia\DNS\Message\Header;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;

final readonly class Message
{
    /**
     * Maximum DNS message size per RFC 1035 Section 4.2.2.
     * TCP framing uses a 2-byte length prefix, so no message may exceed 65535 bytes.
     */
    public const int MAX_SIZE = 65535;

    /**
     * Maximum UDP payload size per RFC 1035 Section 4.2.1 (without EDNS0).
     */
    public const int MAX_UDP_SIZE = 512;

    public const int RCODE_NOERROR = 0;
    public const int RCODE_FORMERR = 1;
    public const int RCODE_SERVFAIL = 2;
    public const int RCODE_NXDOMAIN = 3;
    public const int RCODE_NOTIMP = 4;
    public const int RCODE_REFUSED = 5;
    public const int RCODE_YXDOMAIN = 6;
    public const int RCODE_YXRRSET = 7;
    public const int RCODE_NXRRSET = 8;
    public const int RCODE_NOTAUTH = 9;
    public const int RCODE_NOTZONE = 10;

    /**
     * @param Header $header The header of the message.
     * @param Question[] $questions The question records.
     * @param list<Record> $answers The answer records.
     * @param list<Record> $authority The authority records.
     * @param list<Record> $additional The additional records.
     */
    public function __construct(
        public Header $header,
        /** @var Question[] */
        public array $questions = [],
        /** @var list<Record> */
        public array $answers = [],
        /** @var list<Record> */
        public array $authority = [],
        /** @var list<Record> */
        public array $additional = [],
    ) {
        if ($header->questionCount !== \count($questions)) {
            throw new \InvalidArgumentException('Invalid DNS response: question count mismatch');
        }
        if ($header->answerCount !== \count($answers)) {
            throw new \InvalidArgumentException('Invalid DNS response: answer count mismatch');
        }
        if ($header->authorityCount !== \count($authority)) {
            throw new \InvalidArgumentException('Invalid DNS response: authority count mismatch');
        }
        if ($header->additionalCount !== \count($additional)) {
            throw new \InvalidArgumentException('Invalid DNS response: additional count mismatch');
        }
        $soaAuthorityCount = \count(array_filter(
            $this->authority,
            fn(\Utopia\DNS\Message\Record $record): bool => $record->type === Record::TYPE_SOA,
        ));

        // TC=1 signals an incomplete response, so NODATA/NXDOMAIN invariants
        // that require SOA in authority don't apply — the client will retry
        // over TCP for the full answer.
        if ($header->isResponse && $header->authoritative && !$header->truncated && $soaAuthorityCount < 1) {
            if ($header->responseCode === self::RCODE_NXDOMAIN) {
                throw new \InvalidArgumentException('NXDOMAIN requires SOA in authority');
            }
            if ($header->responseCode === self::RCODE_NOERROR && $answers === []) {
                throw new \InvalidArgumentException('NODATA should include SOA in authority');
            }
        }
    }

    public static function query(
        Question $question,
        ?int $id = null,
        bool $recursionDesired = true,
    ): self {
        if ($id === null) {
            $id = random_int(0, 0xFFFF);
        }

        $header = new Header(
            id: $id,
            isResponse: false,
            opcode: 0, // QUERY
            authoritative: false,
            truncated: false,
            recursionDesired: $recursionDesired,
            recursionAvailable: false,
            responseCode: 0,
            questionCount: 1,
            answerCount: 0,
            authorityCount: 0,
            additionalCount: 0,
        );

        return new self($header, [$question]);
    }

    /**
     * Create a response message.
     *
     * @param Header $header The header of the query message to respond to.
     * @param int $responseCode The response code.
     * @param array<Question> $questions The question records.
     * @param list<Record> $answers The answer records.
     * @param list<Record> $authority The authority records.
     * @param list<Record> $additional The additional records.
     * @param bool $authoritative Whether the response is authoritative.
     * @param bool $truncated Whether the response is truncated.
     * @param bool $recursionAvailable Whether recursion is available.
     * @return self The response message.
     */
    public static function response(
        Header $header,
        int $responseCode,
        array $questions = [],
        array $answers = [],
        array $authority = [],
        array $additional = [],
        bool $authoritative = false,
        bool $truncated = false,
        bool $recursionAvailable = false,
    ): self {
        $header = new Header(
            id: $header->id,
            isResponse: true,
            opcode: $header->opcode,
            authoritative: $authoritative,
            truncated: $truncated,
            recursionDesired: $header->recursionDesired,
            recursionAvailable: $recursionAvailable,
            responseCode: $responseCode,
            questionCount: \count($questions),
            answerCount: \count($answers),
            authorityCount: \count($authority),
            additionalCount: \count($additional),
        );


        return new self($header, $questions, $answers, $authority, $additional);
    }

    public static function decode(string $packet): self
    {
        if (\strlen($packet) < Header::LENGTH) {
            throw new DecodingException('Invalid DNS response: header too short');
        }

        // --- Parse header (12 bytes) ---
        $header = Header::decode($packet);

        // --- Parse Question Section ---
        try {
            $offset = Header::LENGTH;
            $questions = [];
            for ($i = 0; $i < $header->questionCount; $i++) {
                $questions[] = Question::decode($packet, $offset);
            }

            // --- Decode Answer Section ---
            $answers = [];
            for ($i = 0; $i < $header->answerCount; $i++) {
                $answers[] = Record::decode($packet, $offset);
            }

            // --- Decode Authority Section ---
            $authority = [];
            for ($i = 0; $i < $header->authorityCount; $i++) {
                $authority[] = Record::decode($packet, $offset);
            }

            // --- Decode Additional Section ---
            $additional = [];
            for ($i = 0; $i < $header->additionalCount; $i++) {
                $additional[] = Record::decode($packet, $offset);
            }

            if ($offset !== \strlen($packet)) {
                throw new DecodingException('Invalid packet length');
            }
        } catch (DecodingException $e) {
            throw new PartialDecodingException($header, $e->getMessage(), $e);
        }

        return new self($header, $questions, $answers, $authority, $additional);
    }

    /**
     * Encode the message to a binary DNS packet.
     *
     * When maxSize is specified, truncation follows RFC 1035 Section 6.2 and
     * RFC 2181 Section 9:
     * - Sections are dropped from the end first (additional → authority → answers)
     * - Authority and additional are all-or-nothing; answers allow partial inclusion
     * - TC flag is only set when answer records couldn't all fit
     * - Questions are always preserved
     *
     * @param int|null $maxSize Maximum packet size (e.g., 512 for UDP per RFC 1035)
     * @return string The encoded DNS packet
     */
    public function encode(?int $maxSize = null): string
    {
        $packet = $this->header->encode();
        foreach ($this->questions as $question) {
            $packet .= $question->encode();
        }

        // Answers: include as many complete records as fit (partial allowed).
        $answerCount = 0;
        foreach ($this->answers as $answer) {
            $encoded = $answer->encode();
            if ($maxSize !== null && \strlen($packet) + \strlen($encoded) > $maxSize) {
                break;
            }
            $packet .= $encoded;
            $answerCount++;
        }
        $answersTruncated = $answerCount < \count($this->answers);

        // Authority then additional: all-or-nothing, and only once answers all fit.
        // Order matches RFC 1035 Section 6.2 (drop additional before authority).
        $authorityCount = 0;
        $additionalCount = 0;
        if (!$answersTruncated) {
            $withAuthority = $this->appendRecords($packet, $this->authority);
            if ($maxSize === null || \strlen($withAuthority) <= $maxSize) {
                $packet = $withAuthority;
                $authorityCount = \count($this->authority);

                $withAdditional = $this->appendRecords($packet, $this->additional);
                if ($maxSize === null || \strlen($withAdditional) <= $maxSize) {
                    $packet = $withAdditional;
                    $additionalCount = \count($this->additional);
                }
            }
        }

        $sectionsUnchanged
            = $answerCount === \count($this->answers)
            && $authorityCount === \count($this->authority)
            && $additionalCount === \count($this->additional);

        if ($sectionsUnchanged) {
            return $packet;
        }

        // When authority is dropped, an authoritative NODATA/NXDOMAIN response
        // loses the SOA it needs to remain RFC-valid, so clear the AA flag.
        // Use the original message's intent (not post-truncation counts): a
        // TC=1 response that merely encoded zero answers isn't NODATA — the
        // client will retry over TCP and the AA claim remains accurate.
        $authorityDropped = $authorityCount < \count($this->authority);
        $isNodataOrNxdomain = ($this->header->responseCode === self::RCODE_NOERROR && $this->answers === [])
            || $this->header->responseCode === self::RCODE_NXDOMAIN;
        $authoritative = ($authorityDropped && $isNodataOrNxdomain)
            ? false
            : $this->header->authoritative;

        // Per RFC 2181 Section 9, TC signals truncated required data (answers).
        // Preserve an inbound TC=1 (e.g. from a forwarded packet) — dropping
        // additional/authority on re-encode must not silently clear it.
        $header = new Header(
            id: $this->header->id,
            isResponse: $this->header->isResponse,
            opcode: $this->header->opcode,
            authoritative: $authoritative,
            truncated: $answersTruncated || $this->header->truncated,
            recursionDesired: $this->header->recursionDesired,
            recursionAvailable: $this->header->recursionAvailable,
            responseCode: $this->header->responseCode,
            questionCount: \count($this->questions),
            answerCount: $answerCount,
            authorityCount: $authorityCount,
            additionalCount: $additionalCount,
        );

        return $header->encode() . substr($packet, Header::LENGTH);
    }

    /**
     * Validate all response records without encoding the message.
     */
    public function validate(): void
    {
        foreach ([
            $this->answers,
            $this->authority,
            $this->additional,
        ] as $records) {
            foreach ($records as $record) {
                $record->validateRdata();
            }
        }
    }

    /**
     * @param list<Record> $records
     */
    private function appendRecords(string $packet, array $records): string
    {
        foreach ($records as $record) {
            $packet .= $record->encode();
        }
        return $packet;
    }
}
