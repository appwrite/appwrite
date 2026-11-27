<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class RuleRecordRegion extends Model
{
    public function __construct()
    {
        $this
            ->addRule('id', [
                'type' => self::TYPE_STRING,
                'description' => 'Region ID.',
                'default' => '',
                'example' => 'fra',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Region name.',
                'default' => '',
                'example' => 'Frankfurt',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'What the region saw. Possible values are "verified" when it found the expected value, "missing" when it found no record of the type, "mismatch" when it found records without the expected value, or "failed" when its lookup failed.',
                'default' => '',
                'example' => 'verified',
            ])
            ->addRule('current', [
                'type' => self::TYPE_STRING,
                'description' => 'Values the region found for the record type, at most 10. Empty when it found none.',
                'default' => [],
                'example' => ['appwrite.network'],
                'array' => true,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'RuleRecordRegion';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROXY_RULE_RECORD_REGION;
    }
}
