<?php

declare(strict_types=1);

namespace Utopia\Platform\Tests;

use Utopia\Platform\Service;

class TestService extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction('root', TestActionRoot::class);
        $this->addAction('chunked', TestActionChunked::class);
        $this->addAction('redirect', TestActionRedirect::class);
        $this->addAction('initHook', TestActionInit::class);
        $this->addAction('withParams', TestActionWithParams::class);
        $this->addAction('aliased', TestActionAliased::class);
    }
}
