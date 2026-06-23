<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PolicyList extends Model
{
    public function __construct()
    {
        $this
            ->addRule('total', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of policies in the given project.',
                'default' => 0,
                'example' => 10,
            ])
            ->addRule('policies', [
                'type' => $this->getPolicyModels(),
                'description' => 'List of policies.',
                'default' => [],
                'array' => true,
            ]);
    }

    /**
     * @return array<string>
     */
    protected function getPolicyModels(): array
    {
        return [
            Response::MODEL_POLICY_PASSWORD_DICTIONARY,
            Response::MODEL_POLICY_PASSWORD_HISTORY,
            Response::MODEL_POLICY_PASSWORD_STRENGTH,
            Response::MODEL_POLICY_PASSWORD_PERSONAL_DATA,
            Response::MODEL_POLICY_SESSION_ALERT,
            Response::MODEL_POLICY_SESSION_DURATION,
            Response::MODEL_POLICY_SESSION_INVALIDATION,
            Response::MODEL_POLICY_SESSION_LIMIT,
            Response::MODEL_POLICY_USER_LIMIT,
            Response::MODEL_POLICY_MEMBERSHIP_PRIVACY,
        ];
    }

    public function getName(): string
    {
        return 'Policies List';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_LIST;
    }
}
