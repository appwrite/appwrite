<?php

namespace Appwrite\Auth;

use Appwrite\Utopia\Database\Documents\User;

class OwnerRole
{
    /**
     * Decides whether a caller may grant the organization owner role to a membership.
     *
     * Granting owner on an organization (a team on the console project) is a strictly-owner
     * action: it must come from genuine owner authority, not merely from holding an API key.
     * An organization API key carries the base team role and its own scopes, but the key
     * document records no membership role for whoever created it, so a key minted by a
     * lower-privileged member must not be able to escalate a membership to owner. Outside the
     * console project, role assignment is governed by the calling endpoint's own checks and is
     * left untouched here.
     *
     * @param array<string> $roles     The roles being assigned to the membership.
     * @param string        $projectId The project the membership belongs to.
     * @param bool          $isOwner   Whether the caller genuinely holds the team owner role.
     */
    public static function mayAssign(array $roles, string $projectId, bool $isOwner): bool
    {
        if ($projectId !== 'console') {
            return true;
        }

        if (!\in_array(User::ROLE_OWNER, $roles, true)) {
            return true;
        }

        return $isOwner;
    }
}
