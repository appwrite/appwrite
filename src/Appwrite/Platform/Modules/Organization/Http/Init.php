<?php

namespace Appwrite\Platform\Modules\Organization\Http;

use Appwrite\Auth\Key;
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
            ->inject('project')
            ->inject('mode')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    public function action(Document $team, Document $project, string $mode, ?Key $apiKey): void
    {
        // Outside the console, only keys and console sessions in admin mode reach the organization; project session roles come from the project's own teams.
        if ($project->getId() !== 'console' && $apiKey === null && $mode !== APP_MODE_ADMIN) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }
    }
}
