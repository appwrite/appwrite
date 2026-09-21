<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class MFAChallengeSecret extends MFAChallenge
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('code', [
                'type' => self::TYPE_STRING,
                'description' => 'Challenge code to be delivered to the end user through a custom channel.',
                'default' => '',
                'example' => '446372',
            ]);
    }

    /**
     * Get Name
     */
    public function getName(): string
    {
        return 'MFA Challenge Secret';
    }

    /**
     * Get Type
     */
    public function getType(): string
    {
        return Response::MODEL_MFA_CHALLENGE_SECRET;
    }
}
