<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a proxy rule is updated (`rules.[ruleId].update`).
 */
class RuleUpdated extends ResourceEvent
{
    public function __construct(Document $rule, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'rules.[ruleId].update',
            params: ['ruleId' => $rule->getId()],
            document: $rule,
            model: Response::MODEL_PROXY_RULE,
            project: $project,
            user: $actor,
        );
    }
}
