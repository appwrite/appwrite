<?php

namespace Utopia\DNS\Validator;

use Utopia\DNS\Client;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\Domains\Domain;
use Utopia\Validator;

class DNS extends Validator
{
    protected const string FAILURE_REASON_QUERY = 'DNS query failed.';
    protected const string FAILURE_REASON_INTERNAL = 'Internal error occurred.';
    protected const string FAILURE_REASON_UNKNOWN = '';
    protected const string DEFAULT_DNS_SERVER = '8.8.8.8';

    // Memory from isValid to be used in getDescription
    /**
     * @var array<string>
     */
    public array $records = []; // Records found, even if wrong value
    public string $value = ''; // Domain being validated
    public int $count = 0; // Amount of records found
    public string $reason = ''; // Override for when reason is known ahead of time

    /**
     * @param string $target Expected value for the DNS record
     * @param int $type Type of DNS record to validate
     *  For value, use const from Record, such as Record::TYPE_A
     *  When using CAA type, you can provide exact match, or just issuer domain as $target
     * @param string $dnsServer DNS server IP or domain to use for validation
     */
    public function __construct(protected string $target, protected int $type = Record::TYPE_CNAME, protected string $dnsServer = self::DEFAULT_DNS_SERVER) {}

    public function getDescription(): string
    {
        if ($this->reason !== '' && $this->reason !== '0') {
            return $this->reason;
        }

        $typeVerbose = Record::typeCodeToName($this->type) ?? $this->type;

        $messages = [];

        $messages[] = "DNS verification failed with resolver {$this->dnsServer}";

        if ($this->count === 0) {
            $messages[] = 'Domain ' . $this->value . ' is missing ' . $typeVerbose . ' record';
            return implode('. ', $messages) . '.';
        }

        $records = implode("', '", $this->records);

        $countVerbose = match ($this->count) {
            1 => 'one',
            2 => 'two',
            3 => 'three',
            4 => 'four',
            5 => 'five',
            6 => 'six',
            7 => 'seven',
            8 => 'eight',
            9 => 'nine',
            10 => 'ten',
            default => $this->count
        };

        if ($this->count === 1) {
            $messages[] = "Domain {$this->value} has incorrect {$typeVerbose} value '{$records}'";
        } else {
            // Two or more
            $messages[] = "Domain {$this->value} has {$countVerbose} incompatible {$typeVerbose} records: '{$records}'";
        }

        if ($this->type === Record::TYPE_CAA) {
            $messages[] = 'Add new CAA record, or remove all other CAA records';
        }

        return implode('. ', $messages) . '.';
    }

    /**
     * Check if DNS record value matches specific value
     */
    public function isValid(mixed $value): bool
    {
        if (!\is_string($value)) {
            $this->reason = self::FAILURE_REASON_INTERNAL;
            return false;
        }

        $this->count = 0;
        $this->value = \strval($value);
        $this->reason = self::FAILURE_REASON_UNKNOWN;
        $this->records = [];

        $dns = new Client($this->dnsServer);

        try {
            $question = new Question($value, $this->type);
            $queryMessage = Message::query($question, recursionDesired: true);
            $response = $dns->query($queryMessage);
            $answers = $response->answers;

            // Some DNS servers return all records, not only type that's asked for
            // Likely occurs when no records of specific type are found
            $query = array_filter($answers, fn(\Utopia\DNS\Message\Record $record): bool => $record->type === $this->type);
        } catch (\Exception) {
            $this->reason = self::FAILURE_REASON_QUERY;
            return false;
        }

        $this->count = \count($query);

        if ($query === []) {
            // CAA records inherit from parent (custom CAA behaviour)
            if ($this->type === Record::TYPE_CAA) {
                $domain = new Domain($value);
                if ($domain->get() === $domain->getApex()) {
                    return true; // No CAA on apex domain means anyone can issue certificate
                }

                // Recursive validation by parent domain
                $parts = explode('.', $value);
                array_shift($parts);
                $parentDomain = implode('.', $parts);
                $validator = new self($this->target, $this->type, $this->dnsServer);
                $result = $validator->isValid($parentDomain);
                $this->records = $validator->records;
                $this->value = $validator->value;
                $this->count = $validator->count;
                $this->reason = $validator->reason;
                return $result;
            }

            return false;
        }

        foreach ($query as $record) {
            // CAA validation only needs to ensure domain
            if ($this->type === Record::TYPE_CAA) {
                // Extract domain; comments showcase extraction steps in most complex scenario
                $rdata = $record->rdata; // 255 issuewild "certainly.com;validationmethods=tls-alpn-01;retrytimeout=3600"
                $rdata = explode(' ', $rdata, 3)[2] ?? ''; // "certainly.com;validationmethods=tls-alpn-01;retrytimeout=3600"
                $rdata = trim($rdata, '"'); // certainly.com;validationmethods=tls-alpn-01;retrytimeout=3600
                $rdata = explode(';', $rdata, 2)[0]; // certainly.com

                $this->records[] = $rdata;
                if ($rdata === $this->target) {
                    return true;
                }
            } else {
                $this->records[] = $record->rdata;
            }

            if ($record->rdata === $this->target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
