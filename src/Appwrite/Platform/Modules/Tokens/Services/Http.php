<?php

namespace Appwrite\Platform\Modules\Tokens\Services;

use Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files\Create as CreateFileToken;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files\XList as ListFileTokens;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\Delete as DeleteToken;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\Get as GetToken;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\Update as UpdateToken;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this
            ->addAction('createFileToken', CreateFileToken::class)
            ->addAction('getToken', GetToken::class)
            ->addAction('listFileTokens', ListFileTokens::class)
            ->addAction('updateToken', UpdateToken::class)
            ->addAction('deleteToken', DeleteToken::class)
        ;

    }
}
