<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Escalations;

use Appwrite\Alert\Escalation;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * What the escalation routes share: finding an escalation of the
 * organization, and reading the steps a request sends.
 */
class Base extends Action
{
    protected function getEscalation(Database $dbForPlatform, Authorization $authorization, Document $team, string $escalationId): Document
    {
        $escalation = $authorization->skip(fn () => $dbForPlatform->getDocument('escalations', $escalationId));

        if ($escalation->isEmpty() || $escalation->getAttribute('teamInternalId') !== (string) $team->getSequence()) {
            throw new Exception(Exception::ESCALATION_NOT_FOUND);
        }

        return $escalation;
    }

    /**
     * The steps and repeats, each step naming roles the organization has and
     * members it holds.
     *
     * @param array<mixed> $steps
     */
    protected function parseEscalation(array $steps, int $repeat, Database $dbForPlatform, Authorization $authorization, Document $team): Escalation
    {
        try {
            $escalation = Escalation::parse($steps, $repeat, Config::getParam('alerts', [])['roles'] ?? []);
        } catch (\InvalidArgumentException $e) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $e->getMessage());
        }

        $userIds = $escalation->userIds();
        if ($userIds !== []) {
            $members = $authorization->skip(fn () => $dbForPlatform->find('memberships', [
                Query::equal('teamInternalId', [(string) $team->getSequence()]),
                Query::equal('userId', $userIds),
                Query::equal('confirm', [true]),
                Query::limit(\count($userIds)),
            ]));
            $missing = \array_diff($userIds, \array_map(fn (Document $membership) => (string) $membership->getAttribute('userId'), $members));
            if ($missing !== []) {
                throw new Exception(Exception::ESCALATION_MEMBER_NOT_FOUND, 'Not members of the organization: ' . \implode(', ', $missing) . '.');
            }
        }

        return $escalation;
    }

    protected function search(string $escalationId, string $name): string
    {
        return \implode(' ', \array_filter([$escalationId, $name], fn (string $part) => $part !== ''));
    }
}
