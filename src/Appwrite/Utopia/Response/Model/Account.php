<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class Account extends User
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->removeRule('password')
            ->removeRule('hash')
            ->removeRule('mfaRecoveryCodes')
            ->removeRule('hashOptions');
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Account';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ACCOUNT;
    }
}
