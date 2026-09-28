<?php

namespace Utopia\Tests;

use Utopia\Platform\Action;

class TestActionAliased extends Action
{
    public function __construct()
    {
        $this->httpPath = '/aliased';
        $this->setHttpMethod('GET');
        $this->httpAlias('/alias-one');
        $this->httpAlias('/alias-two');
        $this->httpAlias('/alias-three');
        $this->inject('response');
        $this->callback(function ($response): void {
            $this->action($response);
        });
    }

    public function action($response): void
    {
        $response->send('Aliased!');
    }
}
