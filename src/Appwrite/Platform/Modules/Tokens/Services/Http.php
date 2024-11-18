<?php

namespace Appwrite\Platform\Modules\Tokens\Services;

use Appwrite\Platform\Modules\Tokens\Http\Tokens\CreateToken;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\DeleteToken;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\GetToken;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\GetTokenJWT;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\ListTokens;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\UpdateToken;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this
            ->addAction(CreateToken::getName(), new CreateToken())
            ->addAction(DeleteToken::getName(), new DeleteToken())
            ->addAction(GetToken::getName(), new GetToken())
            ->addAction(GetTokenJWT::getName(), new GetTokenJWT())
            ->addAction(ListTokens::getName(), new ListTokens())
            ->addAction(UpdateToken::getName(), new UpdateToken())
        ;

    }
}
