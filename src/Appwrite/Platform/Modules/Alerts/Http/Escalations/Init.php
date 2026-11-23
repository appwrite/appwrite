<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Escalations;

use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Platform\Action;

/**
 * Escalations name the organization's members, so only the console
 * (a member's session or an organization key) reaches them, never a project's key.
 */
class Init extends Action
{
    public static function getName(): string
    {
        return 'initEscalations';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_INIT)
            ->groups(['escalations'])
            ->inject('project')
            ->callback(function (Document $project) {
                if ($project->getId() !== 'console') {
                    throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
                }
            });
    }
}
