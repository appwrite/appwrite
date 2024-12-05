<?php

namespace Appwrite\Platform\Modules\Tokens\Services;

use Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files\CreateFileToken;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files\ListFileTokens;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\DeleteToken;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\GetToken;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\GetTokenJWT;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\UpdateToken;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this
            ->addAction(CreateFileToken::getName(), new CreateFileToken())
            ->addAction(DeleteToken::getName(), new DeleteToken())
            ->addAction(GetToken::getName(), new GetToken())
            ->addAction(GetTokenJWT::getName(), new GetTokenJWT())
            ->addAction(ListFileTokens::getName(), new ListFileTokens())
            ->addAction(UpdateToken::getName(), new UpdateToken())
        ;

    }
}
