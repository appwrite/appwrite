/**
 * Ensures the current user has a personal organization, then sets account
 * prefs so the root redirect sends them to their org.
 *
 * - If the user has no organizations: creates "Personal Projects" (free tier),
 *   sets prefs, returns orgId.
 * - If the user has organizations but no preferred org in prefs: sets prefs to
 *   the first org.
 *
 * The first project is not created here. An organization without projects
 * opens the create project dialog, which suggests the region closest to the
 * visitor; creating it here would land every new project in the API's default
 * region.
 *
 * Self-hosted instances reject additional organization creation. Remember that
 * denial during navigation, but recheck memberships so invitations can grant access.
 *
 * Used after signup (email/OAuth) and after email verification so new users
 * are redirected to their org main page.
 */

import { AppwriteException, type Models } from '@appwrite.io/console'
import type { QueryClient } from '@tanstack/react-query'
import { setConsoleAccountCache } from '@/lib/console-account-cache'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { isCloudProfile } from '@/lib/console-profiles'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'
import {
  createOrganization,
  fetchOrganizationById,
  fetchOrganizations,
  organizationQueryOptions,
  organizationsQueryOptions,
} from '@/lib/react-query/hooks/organizations'
import {
  fetchConsoleAccount,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  consoleTeamQueryOptions,
  fetchConsoleTeam,
} from '@/lib/react-query/hooks/teams'
import { USER_PREFS_KEY_ORGANIZATION } from '@/lib/user-prefs-keys'
import { isSSORequiredError } from '@/lib/sso-required'
import { isHttpNotFoundError } from '@/lib/utils/error-formatting'

export const PERSONAL_ORG_NAME = 'Personal Projects'

function isOrganizationCreationProhibited(
  error: unknown,
): error is AppwriteException {
  return (
    error instanceof AppwriteException &&
    error.code === 403 &&
    error.type === 'organization_creation_prohibited'
  )
}

// Do not retry a policy denial during post-auth prefetch/navigation. Keep this
// in memory so reloading can recover if the instance's organization is deleted.
const prohibitedAccounts = new Map<string, AppwriteException>()

function provisioningAccountKey(accountId: string): string {
  return JSON.stringify([getApiEndpoint(), accountId])
}

/**
 * `open` when the person can use the organization, `missing` when it is gone
 * (or they left it), `refused` when it requires single sign-on and this session
 * did not come from it.
 */
type OrganizationAccess = 'open' | 'missing' | 'refused'

async function organizationAccess(
  orgId: string,
  queryClient?: QueryClient,
): Promise<OrganizationAccess> {
  try {
    const org = queryClient
      ? await queryClient.ensureQueryData(organizationQueryOptions(orgId))
      : await fetchOrganizationById(orgId)
    if (!org) return 'missing'
  } catch (error) {
    if (isHttpNotFoundError(error)) return 'missing'
    if (isSSORequiredError(error)) return 'refused'
    throw error
  }
  // On Cloud the organization comes from the organizations list, which never
  // refuses; the team itself does. Asked fresh: a team cached before the
  // organization started requiring single sign-on would still look open. The
  // answer is cached for the overview, which needs the team anyway.
  try {
    await (queryClient
      ? queryClient.fetchQuery({
          ...consoleTeamQueryOptions(orgId),
          staleTime: 0,
        })
      : fetchConsoleTeam(orgId))
  } catch (error) {
    if (isSSORequiredError(error)) return 'refused'
  }
  return 'open'
}

/**
 * Preferred org from account prefs when still accessible, otherwise ensure a valid org.
 *
 * Pass `queryClient` when available so the org/list queries go through the
 * query cache and are reused by `prefetchOrganizationOverviewData` instead of
 * being fetched twice during initial load.
 *
 * Existence is checked with a single-org get (the same query the overview
 * already needs), not a full organizations list.
 *
 * An organization that requires single sign-on, opened on a session that did
 * not come from it, is passed over for another one the person can open. The
 * preference keeps naming it, so after signing in with SSO they return to it.
 * When no organization can be opened, the refused one is returned: opening it
 * sends the person to sign in with SSO.
 */
export async function resolvePostAuthOrganizationId(
  account?: Awaited<ReturnType<typeof fetchConsoleAccount>>,
  queryClient?: QueryClient,
): Promise<string> {
  const resolved = account ?? (await fetchConsoleAccount())
  const prefs = (resolved.prefs || {}) as Record<string, unknown>
  const fromPrefs = prefs[USER_PREFS_KEY_ORGANIZATION]
  const preferredId =
    typeof fromPrefs === 'string' && fromPrefs.trim()
      ? fromPrefs.trim()
      : undefined

  if (preferredId) {
    const access = await organizationAccess(preferredId, queryClient)
    if (access === 'open') {
      prohibitedAccounts.delete(provisioningAccountKey(resolved.$id))
      return preferredId
    }
    if (access === 'refused') {
      return await ensurePersonalOrg(queryClient, [preferredId])
    }

    const restPrefs = { ...prefs }
    delete restPrefs[USER_PREFS_KEY_ORGANIZATION]
    const updatedAccount = await updateAccountPrefs(restPrefs)
    if (
      updatedAccount &&
      typeof updatedAccount === 'object' &&
      '$id' in updatedAccount
    ) {
      setConsoleAccountCache(
        updatedAccount as Models.User,
        getConsoleAccountQueryRevision(),
      )
    }
  }

  return await ensurePersonalOrg(queryClient)
}

/**
 * Write a prefs update back to the account cache so the next
 * `resolvePostAuthOrganizationId(account)` sees the organization preference
 * instead of re-entering provisioning with a stale account.
 */
function rememberAccount(updated: Models.User | undefined): void {
  if (updated && typeof updated === 'object' && '$id' in updated) {
    setConsoleAccountCache(updated, getConsoleAccountQueryRevision())
  }
}

let inflight: Promise<string> | null = null

/**
 * Single-flight: sign-up, the post-auth prefetch and the root loader can all
 * call this within the same tick. Running them concurrently used to create a
 * second "Personal Projects".
 */
export function ensurePersonalOrg(
  queryClient?: QueryClient,
  refused: string[] = [],
): Promise<string> {
  if (inflight) return inflight
  inflight = provisionPersonalOrg(queryClient, refused).finally(() => {
    inflight = null
  })
  return inflight
}

async function provisionPersonalOrg(
  queryClient: QueryClient | undefined,
  refused: string[],
): Promise<string> {
  const account = await fetchConsoleAccount()
  const prefs = (account.prefs || {}) as Record<string, unknown>

  const accountKey = provisioningAccountKey(account.$id)
  const prohibited = !isCloudProfile()
    ? prohibitedAccounts.get(accountKey)
    : undefined
  // Membership may have become available through an invitation. Recheck it,
  // rather than letting a cached empty list make a policy denial permanent.
  const response = queryClient
    ? prohibited
      ? await queryClient.fetchQuery({
          ...organizationsQueryOptions(),
          staleTime: 0,
        })
      : await queryClient.ensureQueryData(organizationsQueryOptions())
    : await fetchOrganizations()
  const orgs = response.teams || []

  if (orgs.length === 0) {
    if (prohibited) throw prohibited
    const created = await createOrganization({ name: PERSONAL_ORG_NAME }).catch(
      (error: unknown) => {
        if (!isCloudProfile() && isOrganizationCreationProhibited(error)) {
          prohibitedAccounts.set(accountKey, error)
        }
        throw error
      },
    )
    if (!('$id' in created)) {
      throw new Error(
        'Creating the personal organization requires payment authentication',
      )
    }
    const org: Models.Team = created
    const orgId = org.$id

    // The cached list was fetched before the org existed; a concurrent
    // loader must not read it as "no organizations" and create another.
    queryClient?.setQueryData(organizationsQueryOptions().queryKey, {
      ...response,
      teams: [org],
      total: 1,
    })

    rememberAccount(
      await updateAccountPrefs({
        ...prefs,
        [USER_PREFS_KEY_ORGANIZATION]: orgId,
      }),
    )
    return orgId
  }

  prohibitedAccounts.delete(accountKey)
  const savedId = prefs[USER_PREFS_KEY_ORGANIZATION] as string | undefined
  if (savedId && !refused.includes(savedId)) return savedId

  // The first organization the person can open. One that requires single
  // sign-on is skipped, and never saved as the preference.
  let orgId: string | undefined
  for (const org of orgs) {
    if (refused.includes(org.$id)) continue
    const access = await organizationAccess(org.$id, queryClient)
    if (access === 'open') {
      orgId = org.$id
      break
    }
    if (access === 'refused') refused.push(org.$id)
  }
  if (!orgId) return refused[0] ?? orgs[0].$id

  if (!savedId) {
    rememberAccount(
      await updateAccountPrefs({
        ...prefs,
        [USER_PREFS_KEY_ORGANIZATION]: orgId,
      }),
    )
  }

  return orgId
}
