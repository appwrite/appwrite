import { parse } from 'tldts'

/**
 * Whose single sign-on a screen sets up. Both go through the same Teams API
 * routes (`/teams/{teamId}/sso/*`):
 *
 * - an organization's, for single sign-on into the Console, with the Console's
 *   client (`X-Appwrite-Project: console`);
 * - a team of a project's, for single sign-on into the customer's own app,
 *   with the project's client (`X-Appwrite-Project: <projectId>`, admin mode).
 */
export type SSOTarget =
  | { scope: 'organization'; organizationId: string }
  | { scope: 'project'; projectId: string; teamId: string }

/** The team the Teams API routes are called on: the organization is a team too. */
export function ssoTargetTeamId(target: SSOTarget): string {
  return target.scope === 'organization' ? target.organizationId : target.teamId
}

/**
 * Query key of a target's domains (`emailDomains`) or identity providers
 * (`ssoProviders`). An organization keeps the keys it always had; a project's
 * team is keyed by the project too, so two caches never mix.
 */
export function ssoQueryKey(
  resource: 'emailDomains' | 'ssoProviders',
  target: SSOTarget | null,
): readonly unknown[] {
  if (!target) return [resource, 'none']
  return target.scope === 'organization'
    ? [resource, 'organization', target.organizationId]
    : [resource, 'project', target.projectId, target.teamId]
}

/** Query key of the document that carries `ssoRequired`: the organization, or the team. */
export function ssoTeamQueryKey(target: SSOTarget): readonly unknown[] {
  return target.scope === 'organization'
    ? ['organization', target.organizationId]
    : ['team', 'project', target.projectId, target.teamId]
}

/** Most characters a role of a project's team can have, as for memberships. */
export const TEAM_ROLE_MAX_LENGTH = 36

export type TeamRoleError = 'empty' | 'length' | 'format'

/**
 * Why `role` is not a role a project's team can give, or null when it is. Roles
 * are keys, like the roles of a membership: letters, digits, period, hyphen and
 * underscore, at most 36 characters, not starting with a special character.
 */
export function teamRoleError(role: string): TeamRoleError | null {
  const value = role.trim()
  if (value === '') return 'empty'
  if (value.length > TEAM_ROLE_MAX_LENGTH) return 'length'
  for (const char of value) {
    const isLetter =
      (char >= 'a' && char <= 'z') || (char >= 'A' && char <= 'Z')
    const isDigit = char >= '0' && char <= '9'
    if (!isLetter && !isDigit && char !== '.' && char !== '-' && char !== '_') {
      return 'format'
    }
  }
  const first = value[0]
  if (first === '.' || first === '-' || first === '_') return 'format'
  return null
}

/**
 * The TXT record that proves a domain, as the API answered it on the domain
 * (`recordName`, `recordValue`): an organization's is at `_appwrite.<domain>`,
 * a project team's at `_<projectId>.<domain>`, and the Console never builds
 * either. The name is shown the way DNS providers ask for it, relative to the
 * zone (the registrable domain), or `@` for the zone itself.
 */
export function getSSODomainRecord(rule: {
  domain: string
  recordName: string
  recordValue: string
}) {
  return {
    type: 'TXT' as const,
    name: recordNameInZone(rule.recordName, rule.domain),
    value: rule.recordValue,
    ttl: 3600,
  }
}

function recordNameInZone(recordName: string, domain: string): string {
  const name = recordName.trim().endsWith('.')
    ? recordName.trim().slice(0, -1)
    : recordName.trim()
  // The zone a user manages can sit under a private suffix (foo.eu.org), the
  // same way the server reads the registrable domain.
  const zone = parse(domain.trim().toLowerCase(), {
    allowPrivateDomains: true,
  }).domain
  if (!zone) return name
  const lower = name.toLowerCase()
  if (lower === zone) return '@'
  return lower.endsWith(`.${zone}`) ? name.slice(0, -(zone.length + 1)) : name
}
