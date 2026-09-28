<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Http\Response;
use Utopia\Platform\Action;

class TestActionInit extends Action
{
    public function __construct()
    {
        $this->type = Action::TYPE_INIT;
        $this->groups(['test']);
        $this->inject('response');
        $this->callback(function (\Utopia\Http\Response $response): void {
            $this->action($response);
        });
    }

    public function action(Response $response): void
    {
        $response->addHeader('x-init', 'init-called');
    }
}
