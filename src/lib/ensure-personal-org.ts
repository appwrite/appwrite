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
 * Used after signup (email/OAuth) and after email verification so new users
 * are redirected to their org main page.
 */

import { ID, type Models } from '@appwrite.io/console'
import type { QueryClient } from '@tanstack/react-query'
import { setConsoleAccountCache } from '@/lib/console-account-cache'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'
import { createConsoleProject } from '@/lib/appwrite/console-projects'
import { sdk } from '@/lib/appwrite/sdk'
import { createOrganization } from '@/lib/react-query/hooks/organizations'
import { fetchOrganizations } from '@/lib/react-query/hooks/organizations'
import { fetchOrganizationProjects } from '@/lib/react-query/hooks/organizations'
import { organizationsQueryOptions } from '@/lib/react-query/hooks/organizations'
import {
  fetchConsoleAccount,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import { USER_PREFS_KEY_ORGANIZATION } from '@/lib/user-prefs-keys'

const PERSONAL_ORG_NAME = 'Personal Projects'
const FIRST_PROJECT_NAME = 'My first project'

/**
 * Preferred org from account prefs when still accessible, otherwise ensure a valid org.
 *
 * Pass `queryClient` when available so the organizations list goes through the
 * query cache and is reused by `prefetchOrganizationOverviewData` instead of
 * being fetched twice during initial load.
 */
export async function resolvePostAuthOrganizationId(
  account?: Awaited<ReturnType<typeof fetchConsoleAccount>>,
  queryClient?: QueryClient,
): Promise<string> {
  const resolved = account ?? (await fetchConsoleAccount())
  const prefs = (resolved.prefs || {}) as Record<string, unknown>
  const fromPrefs = prefs[USER_PREFS_KEY_ORGANIZATION] as string | undefined

  if (fromPrefs) {
    const response = queryClient
      ? await queryClient.ensureQueryData(organizationsQueryOptions())
      : await fetchOrganizations()
    const exists = response.teams?.some((org) => org.$id === fromPrefs)
    if (exists) return fromPrefs

    const { [USER_PREFS_KEY_ORGANIZATION]: _removed, ...restPrefs } = prefs
    const updatedAccount = await updateAccountPrefs(restPrefs)
    if (updatedAccount && typeof updatedAccount === 'object' && '$id' in updatedAccount) {
      setConsoleAccountCache(
        updatedAccount as Models.User,
        getConsoleAccountQueryRevision(),
      )
    }
  }

  return await ensurePersonalOrgAndFirstProject()
}

export async function ensurePersonalOrgAndFirstProject(): Promise<string> {
  const account = await fetchConsoleAccount()
  const prefs = (account.prefs || {}) as Record<string, unknown>

  const response = await fetchOrganizations()
  const orgs = response.teams || []

  if (orgs.length === 0) {
    const org = await createOrganization({ name: PERSONAL_ORG_NAME })
    const orgId = org.$id

    await updateAccountPrefs({
      ...prefs,
      [USER_PREFS_KEY_ORGANIZATION]: orgId,
    })

    await createConsoleProject({
      projectId: ID.unique(),
      name: FIRST_PROJECT_NAME,
      teamId: orgId,
    })
    return orgId
  }

  const orgId =
    (prefs[USER_PREFS_KEY_ORGANIZATION] as string) || orgs[0].$id

  if (!prefs[USER_PREFS_KEY_ORGANIZATION]) {
    await updateAccountPrefs({
      ...prefs,
      [USER_PREFS_KEY_ORGANIZATION]: orgId,
    })
  }

  const { total } = await fetchOrganizationProjects(orgId)
  if (total === 0) {
    await createConsoleProject({
      projectId: ID.unique(),
      name: FIRST_PROJECT_NAME,
      teamId: orgId,
    })
  }

  return orgId
}
