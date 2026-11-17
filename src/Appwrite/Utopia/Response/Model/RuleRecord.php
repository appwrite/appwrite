<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class RuleRecord extends Model
{
    public function __construct()
    {
        $this
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'DNS record type. Possible values are "CNAME", "A", "AAAA", or "CAA".',
                'default' => '',
                'example' => 'CNAME',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Hostname the record was looked up for.',
                'default' => '',
                'example' => 'appwrite.company.com',
            ])
            ->addRule('value', [
                'type' => self::TYPE_STRING,
                'description' => 'Value the record is expected to have.',
                'default' => '',
                'example' => 'appwrite.network',
            ])
            ->addRule('found', [
                'type' => self::TYPE_STRING,
                'description' => 'Values DNS returned for the record type. Empty when none were found.',
                'default' => [],
                'example' => ['appwrite.network'],
                'array' => true,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Check status. Possible values are "verified" when the expected value was found, "missing" when no record of the type exists yet, "mismatch" when records exist but none has the expected value, or "failed" when the lookup itself failed.',
                'default' => '',
                'example' => 'verified',
            ])
            ->addRule('message', [
                'type' => self::TYPE_STRING,
                'description' => 'Why the check did not pass. Empty when verified.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('required', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the record must be verified on its own. Records that are not required are alternatives: the domain passes when any one of them is verified.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('checkedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'When DNS was checked for this record, in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'RuleRecord';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROXY_RULE_RECORD;
    }
}
