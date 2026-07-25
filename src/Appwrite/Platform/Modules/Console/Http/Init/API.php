<?php

namespace Appwrite\Platform\Modules\Console\Http\Init;

use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Platform\Action;

class API extends Action
{
    public static function getName(): string
    {
        return 'consoleAPI';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_INIT)
            ->groups(['console'])
            ->inject('project')
            ->callback(function (Document $project) {
                if ($project->getId() !== 'console') {
                    throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
                }
            });
    }
}
