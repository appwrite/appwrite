<?php

namespace Utopia\Tests;

use Utopia\Platform\Action;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class TestActionCLI extends Action
{
    public function __construct()
    {
        $this
            ->param('email', null, new Text(0), '')
            ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
            ->callback(function (string $email, $list): void {
                $this->action($email, $list);
            });
    }

    public function action(string $email, $list): void
    {
        echo $email . '-' . implode('-', $list);
    }
}
