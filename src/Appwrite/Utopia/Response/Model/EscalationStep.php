<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class EscalationStep extends Model
{
    public function __construct()
    {
        $this
            ->addRule('delay', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Seconds to wait before notifying this step: after the alert fires for the first step, after the previous step for the others.',
                'default' => 0,
                'example' => 600,
            ])
            ->addRule('roles', [
                'type' => self::TYPE_STRING,
                'description' => 'Organization roles whose confirmed members this step notifies.',
                'default' => [],
                'example' => ['developer'],
                'array' => true,
            ])
            ->addRule('userIds', [
                'type' => self::TYPE_STRING,
                'description' => 'IDs of the organization\'s members this step notifies, whatever their role.',
                'default' => [],
                'example' => ['5e5ea5c16897e'],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return 'Escalation Step';
    }

    public function getType(): string
    {
        return Response::MODEL_ESCALATION_STEP;
    }
}
