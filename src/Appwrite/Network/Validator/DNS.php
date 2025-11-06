<?php

namespace Appwrite\Network\Validator;

use Swoole\Coroutine\WaitGroup;
use Utopia\DNS\Client;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\Domains\Domain;
use Utopia\System\System;
use Utopia\Validator;

class DNS extends Validator
{
    public function __construct(
        protected string $target,
        protected int $type = Record::TYPE_CNAME,
        protected string $server = ''
    ) {
        $this->server = $server ?: System::getEnv('_APP_DNS', '8.8.8.8');
    }

    public function getDescription(): string
    {
        return 'Invalid DNS record.';
    }

    public function isValid($value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $client = new Client($this->server);
        try {
            $response = $client->query(Message::query(
                new Question($value, $this->type)
            ));
        } catch (\Throwable) {
            return false;
        }

        $this->count = \count($query);

        $typeMatches = array_filter(
            $response->answers,
            fn (Record $record) => $record->type === $this->type
        );

        if (empty($typeMatches)) {
            if ($this->type === Record::TYPE_CAA) {
                return $this->validateParentCAA($value);
            }

            return false;
        }

        foreach ($typeMatches as $record) {
            if ($this->type === Record::TYPE_CAA) {
                $valuePart = $this->extractCAAValue($record->rdata);
                if ($valuePart !== '' && $valuePart === $this->target) {
                    return true;
                }
            } else {
                $this->recordValues[] = $record->getRdata();
            }

            if ($record->rdata === $this->target) {
                return true;
            }
        }

        return false;
    }

    private function validateParentCAA(string $domain): bool
    {
        try {
            $domainInfo = new Domain($domain);
        } catch (\Throwable) {
            return false;
        }

        if ($domainInfo->get() === $domainInfo->getApex()) {
            return true;
        }

        $parts = explode('.', $domainInfo->get());
        array_shift($parts);
        $parent = implode('.', $parts);

        if ($parent === '') {
            return false;
        }

        $validator = new self($this->target, Record::TYPE_CAA, $this->server);
        return $validator->isValid($parent);
    }

    private function extractCAAValue(string $rdata): string
    {
        $parts = explode(' ', $rdata, 3);
        if (count($parts) < 3) {
            return '';
        }

        $value = trim($parts[2], '"');
        return explode(';', $value)[0] ?? '';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
