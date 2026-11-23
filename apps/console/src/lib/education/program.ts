/**
 * GitHub Student Developer Pack enrollment.
 *
 * Cloud models the program as a console "program membership": creating one
 * validates the account's GitHub identity against education.github.com and, on
 * success, provisions the Education plan organization and returns it. The
 * console only has to link a GitHub identity, call the endpoint, and route the
 * student into the organization it hands back.
 */

import type { QueryClient } from '@tanstack/react-query'
import { OAuthProvider, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import { EDUCATION_JOIN_PATH } from '@/lib/education/paths'
import { USER_PREFS_KEY_ORGANIZATION } from '@/lib/user-prefs-keys'

export const GITHUB_STUDENT_PROGRAM_ID = 'github-student-developer'

/**
 * The backend verifies the pack by calling education.github.com with the stored
 * provider token, so the identity has to be created with user read access.
 */
const GITHUB_STUDENT_OAUTH_SCOPES = ['read:user', 'user:email']

export function hasGithubIdentity(
  identities: Models.Identity[] | undefined,
): boolean {
  return !!identities?.some(
    (identity) => identity.provider === OAuthProvider.Github,
  )
}

/**
 * Sign up with (or link) GitHub and come back here. Appwrite attaches the new
 * identity to the signed-in account when a session already exists, so this
 * covers both the new student and the one who signed up with email first.
 */
export async function connectGithubForStudentProgram(): Promise<void> {
  const returnUrl = `${window.location.origin}${EDUCATION_JOIN_PATH}`
  const url = await sdk.forConsole.account.createOAuth2Session({
    provider: OAuthProvider.Github,
    success: returnUrl,
    failure: `${returnUrl}?status=failure`,
    scopes: GITHUB_STUDENT_OAUTH_SCOPES,
  })

  // The SDK navigates on its own in the browser; a returned URL means it did not.
  if (typeof url === 'string') {
    window.location.href = url
  }
}

/**
 * Enroll the signed-in account and return the Education plan organization.
 *
 * Throws the raw AppwriteException so callers can tell "not a student" (403)
 * and "already enrolled" (409) apart.
 */
export async function joinGithubStudentProgram(): Promise<Models.Organization> {
  return await sdk.forConsole.console.createProgramMembership({
    programId: GITHUB_STUDENT_PROGRAM_ID,
  })
}

/**
 * Make the Education organization the one the console opens by default, so the
 * student lands on their plan instead of a personal org on the next visit.
 */
export async function rememberEducationOrganization(
  queryClient: QueryClient,
  account: Pick<Models.User, 'prefs'> | null | undefined,
  organizationId: string,
): Promise<void> {
  const prefs = (account?.prefs ?? {}) as Record<string, unknown>
  const updatedAccount = await updateAccountPrefs(
    { ...prefs, [USER_PREFS_KEY_ORGANIZATION]: organizationId },
    'education-program-join',
  )
  if (updatedAccount) {
    syncConsoleAccountAfterMutation(queryClient, { apiResult: updatedAccount })
  }
}
