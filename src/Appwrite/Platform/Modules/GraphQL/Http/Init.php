<?php

namespace Appwrite\Platform\Modules\GraphQL\Http;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
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
            ->groups(['graphql'])
            ->inject('project')
            ->inject('user')
            ->inject('request')
            ->inject('response')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(Document $project, User $user, Request $request, Response $response, Authorization $authorization): void
    {
        $response->setUser($user);
        $request->setUser($user);

        if (
            array_key_exists('graphql', $project->getAttribute('apis', []))
            && !$project->getAttribute('apis', [])['graphql']
            && !($user->isPrivileged($authorization->getRoles()) || $user->isKey($authorization->getRoles()))
        ) {
            throw new AppwriteException(AppwriteException::GENERAL_API_DISABLED);
        }
    }
}
