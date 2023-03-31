<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Type;

#[Name('Account')]
#[Type(Response::MODEL_ACCOUNT)]
class Account extends User
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->removeRule('password')
            ->removeRule('hash')
            ->removeRule('hashOptions');
    }
}
