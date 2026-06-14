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
import { setConsoleAccountCache } from '@/lib/console-account-cache'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'
import { createConsoleProject } from '@/lib/appwrite/console-projects'
import { sdk } from '@/lib/appwrite/sdk'
import { createOrganization } from '@/lib/react-query/hooks/organizations'
import { fetchOrganizations } from '@/lib/react-query/hooks/organizations'
import { fetchOrganizationProjects } from '@/lib/react-query/hooks/organizations'
import {
  fetchConsoleAccount,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'

const PERSONAL_ORG_NAME = 'Personal Projects'
const FIRST_PROJECT_NAME = 'My first project'

/** Preferred org from account prefs when still accessible, otherwise ensure a valid org. */
export async function resolvePostAuthOrganizationId(
  account?: Awaited<ReturnType<typeof fetchConsoleAccount>>,
): Promise<string> {
  const resolved = account ?? (await fetchConsoleAccount())
  const prefs = (resolved.prefs || {}) as Record<string, unknown>
  const fromPrefs = prefs.organization as string | undefined

  if (fromPrefs) {
    const response = await fetchOrganizations()
    const exists = response.teams?.some((org) => org.$id === fromPrefs)
    if (exists) return fromPrefs

    const { organization: _removed, ...restPrefs } = prefs
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
      organization: orgId,
    })

    await createConsoleProject({
      projectId: ID.unique(),
      name: FIRST_PROJECT_NAME,
      teamId: orgId,
    })
    return orgId
  }

  const orgId = (prefs.organization as string) || orgs[0].$id

  if (!prefs.organization) {
    await updateAccountPrefs({
      ...prefs,
      organization: orgId,
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
