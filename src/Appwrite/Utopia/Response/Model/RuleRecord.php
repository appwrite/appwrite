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
