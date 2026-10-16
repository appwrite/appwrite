<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class TokenSecret extends Token
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Token secret key. Exchange it for a session with the create session endpoint.',
                'default' => '',
                'example' => '',
            ]);
    }

    /**
     * Get Name
     */
    public function getName(): string
    {
        return 'Token Secret';
    }

    /**
     * Get Type
     */
    public function getType(): string
    {
        return Response::MODEL_TOKEN_SECRET;
    }
}
