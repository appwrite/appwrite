<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MFARecoveryCodes extends Model
{
    public function __construct()
    {
        $this
            ->addRule('recoveryCodes', [
                'type' => self::TYPE_STRING,
                'format' => 'password',
                'description' => 'Recovery codes.',
                'array' => true,
                'default' => [],
                'example' => ['a3kf0-s0cl2', 's0co1-as98s']
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'MFA Recovery Codes';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MFA_RECOVERY_CODES;
    }
}
