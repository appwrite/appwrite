<?php

namespace Appwrite\Platform\Modules\Project\Http;

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
            ->groups(['project'])
            ->inject('project')
            ->callback(function (Document $project) {
                if ($project->getId() === 'console') {
                    throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
                }

                if ($project->isEmpty()) {
                    throw new Exception(Exception::PROJECT_NOT_FOUND);
                }
            });
    }
}
