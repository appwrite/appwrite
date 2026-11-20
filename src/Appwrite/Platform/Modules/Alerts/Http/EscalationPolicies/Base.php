<?php

namespace Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies;

use Appwrite\Alert\Policy;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * What the escalation policy routes share: finding a policy of the
 * organization, and reading the steps a request sends.
 */
class Base extends Action
{
    protected function getPolicy(Database $dbForPlatform, Authorization $authorization, Document $team, string $escalationPolicyId): Document
    {
        $policy = $authorization->skip(fn () => $dbForPlatform->getDocument('escalationPolicies', $escalationPolicyId));

        if ($policy->isEmpty() || $policy->getAttribute('teamInternalId') !== (string) $team->getSequence()) {
            throw new Exception(Exception::ESCALATION_POLICY_NOT_FOUND);
        }

        return $policy;
    }

    /**
     * The steps and repeats, each step naming roles the organization has and
     * members it holds.
     *
     * @param array<mixed> $steps
     */
    protected function parsePolicy(array $steps, int $repeat, Database $dbForPlatform, Authorization $authorization, Document $team): Policy
    {
        try {
            $policy = Policy::parse($steps, $repeat, Config::getParam('alerts', [])['roles'] ?? []);
        } catch (\InvalidArgumentException $e) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $e->getMessage());
        }

        $userIds = $policy->userIds();
        if ($userIds !== []) {
            $members = $authorization->skip(fn () => $dbForPlatform->find('memberships', [
                Query::equal('teamInternalId', [(string) $team->getSequence()]),
                Query::equal('userId', $userIds),
                Query::limit(\count($userIds)),
            ]));
            $missing = \array_diff($userIds, \array_map(fn (Document $membership) => (string) $membership->getAttribute('userId'), $members));
            if ($missing !== []) {
                throw new Exception(Exception::ESCALATION_POLICY_MEMBER_NOT_FOUND, 'Not members of the organization: ' . \implode(', ', $missing) . '.');
            }
        }

        return $policy;
    }

    protected function search(string $escalationPolicyId, string $name): string
    {
        return \implode(' ', \array_filter([$escalationPolicyId, $name], fn (string $part) => $part !== ''));
    }
}
