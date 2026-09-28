<?php

namespace Utopia\Tests;

use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class TestActionWithParams extends Action
{
    public function __construct()
    {
        $this->httpPath = '/with-params';
        $this->setHttpMethod('GET');

        $this
            ->param('name', '', new Text(128), 'User name.', false, example: 'John Doe')
            ->param('age', 0, new Range(0, 150), 'User age.', true, example: '25')
            ->param('active', false, new Boolean(true), 'Is active.', true, deprecated: true, example: 'true')
            ->param('email', '', new Text(256), 'User email.', true, example: 'user@example.com', aliases: ['emailAddress', 'userEmail'])
            ->param('status', 'draft', new WhiteList(['draft', 'published']), 'Status.', optional: true, enum: new Enum(name: 'ArticleStatus', map: ['draft' => 'Draft', 'published' => 'Published']))
            ->inject('response')
            ->callback(function ($name, $age, $active, $email, $status, $response): void {
                $response->send('OK');
            });
    }
}
