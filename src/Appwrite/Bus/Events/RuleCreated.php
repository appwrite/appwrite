<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a proxy rule is created (`rules.[ruleId].create`).
 */
class RuleCreated extends ResourceEvent
{
    public function __construct(Document $rule, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'rules.[ruleId].create',
            params: ['ruleId' => $rule->getId()],
            document: $rule,
            model: Response::MODEL_PROXY_RULE,
            project: $project,
            user: $actor,
        );
    }
}
