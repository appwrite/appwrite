<?php

namespace Appwrite\Platform\Modules\Organization\Http;

use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Platform\Action;

class Init extends Action
{
    public static function getName(): string
    {
        return 'init';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_INIT)
            ->groups(['organization'])
            ->inject('team')
            ->callback(function (Document $team) {
                if ($team->isEmpty()) {
                    throw new Exception(Exception::TEAM_NOT_FOUND);
                }
            });
    }
}
