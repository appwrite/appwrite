<?php

namespace Appwrite\Platform\Modules\Tokens\Services;

use Appwrite\Platform\Modules\Storage\Http\Tokens\Buckets\Files\Create as CreateFileToken;
use Appwrite\Platform\Modules\Storage\Http\Tokens\Buckets\Files\XList as ListFileTokens;
use Appwrite\Platform\Modules\Storage\Http\Tokens\Delete as DeleteToken;
use Appwrite\Platform\Modules\Storage\Http\Tokens\Get as GetToken;
use Appwrite\Platform\Modules\Storage\Http\Tokens\JWT\Get as GetTokenJWT;
use Appwrite\Platform\Modules\Storage\Http\Tokens\Update as UpdateToken;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this
            ->addAction(CreateFileToken::getName(), new CreateFileToken())
            ->addAction(GetToken::getName(), new GetToken())
            ->addAction(GetTokenJWT::getName(), new GetTokenJWT())
            ->addAction(ListFileTokens::getName(), new ListFileTokens())
            ->addAction(UpdateToken::getName(), new UpdateToken())
            ->addAction(DeleteToken::getName(), new DeleteToken())
        ;

    }
}
