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
            ->addAction(CreateFileToken::getName(), new CreateFileToken())
            ->addAction(GetToken::getName(), new GetToken())
            ->addAction(ListFileTokens::getName(), new ListFileTokens())
            ->addAction(UpdateToken::getName(), new UpdateToken())
            ->addAction(DeleteToken::getName(), new DeleteToken())
        ;

    }
}
