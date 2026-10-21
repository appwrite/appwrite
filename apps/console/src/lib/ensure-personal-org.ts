/**
 * Ensures the current user has a personal organization and at least one project,
 * then sets account prefs so the root redirect sends them to their org.
 *
 * - If the user has no organizations: creates "Personal Projects" (free tier)
 *   and "My first project", sets prefs, returns orgId.
 * - If the user has organizations but no preferred org in prefs: sets prefs to
 *   the first org and ensures that org has at least one project (creates
 *   "My first project" if none).
 *
 * Self-hosted instances reject additional organization creation. Remember that
 * denial during navigation, but recheck memberships so invitations can grant access.
 *
 * Used after signup (email/OAuth) and after email verification so new users
 * are redirected to their org main page.
 */

import { AppwriteException, ID, Query, type Models } from '@appwrite.io/console'
import type { QueryClient } from '@tanstack/react-query'
import { setConsoleAccountCache } from '@/lib/console-account-cache'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { isCloudProfile } from '@/lib/console-profiles'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'
import {
  createConsoleProject,
  listConsoleProjects,
} from '@/lib/appwrite/console-projects'
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
import { USER_PREFS_KEY_ORGANIZATION } from '@/lib/user-prefs-keys'
import { isHttpNotFoundError } from '@/lib/utils/error-formatting'

const PERSONAL_ORG_NAME = 'Personal Projects'
const FIRST_PROJECT_NAME = 'My first project'

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

async function organizationIsAccessible(
  orgId: string,
  queryClient?: QueryClient,
): Promise<boolean> {
  try {
    const org = queryClient
      ? await queryClient.ensureQueryData(organizationQueryOptions(orgId))
      : await fetchOrganizationById(orgId)
    return !!org
  } catch (error) {
    if (isHttpNotFoundError(error)) return false
    throw error
  }
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
    if (await organizationIsAccessible(preferredId, queryClient)) {
      prohibitedAccounts.delete(provisioningAccountKey(resolved.$id))
      return preferredId
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

  return await ensurePersonalOrgAndFirstProject(queryClient)
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
 * second "Personal Projects" / "My first project".
 */
export function ensurePersonalOrgAndFirstProject(
  queryClient?: QueryClient,
): Promise<string> {
  if (inflight) return inflight
  inflight = provisionPersonalOrgAndFirstProject(queryClient).finally(() => {
    inflight = null
  })
  return inflight
}

async function provisionPersonalOrgAndFirstProject(
  queryClient?: QueryClient,
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

    await createConsoleProject({
      projectId: ID.unique(),
      name: FIRST_PROJECT_NAME,
      teamId: orgId,
    })
    return orgId
  }

  prohibitedAccounts.delete(accountKey)
  const orgId = (prefs[USER_PREFS_KEY_ORGANIZATION] as string) || orgs[0].$id

  if (!prefs[USER_PREFS_KEY_ORGANIZATION]) {
    rememberAccount(
      await updateAccountPrefs({
        ...prefs,
        [USER_PREFS_KEY_ORGANIZATION]: orgId,
      }),
    )
  }

  // A failed listing must abort provisioning rather than read as "no
  // projects" and create a duplicate, so this does not go through the
  // error-swallowing fetchOrganizationProjects().
  const { total } = await listConsoleProjects({
    organizationId: orgId,
    queries: [Query.limit(1)],
    total: true,
  })
  if (total === 0) {
    await createConsoleProject({
      projectId: ID.unique(),
      name: FIRST_PROJECT_NAME,
      teamId: orgId,
    })
  }

  return orgId
}
