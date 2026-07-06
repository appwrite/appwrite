<?php

namespace Appwrite\Utopia;

use Swoole\Http\Request as SwooleRequest;
use Utopia\Http\Adapter\Swoole\Request as UtopiaRequest;
use Utopia\System\System;

class Request extends UtopiaRequest
{
    public function __construct(SwooleRequest $request)
    {
        $trustedHeaders = System::getEnv('_APP_TRUSTED_HEADERS', 'x-forwarded-for');
        $this->setTrustedIpHeaders(explode(',', $trustedHeaders));

        parent::__construct($request);
    }
}
