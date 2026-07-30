import type { QueryClient } from '@tanstack/react-query'
import { redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import type { Models } from '@appwrite.io/console'
import {
  ensureConsoleAccountQueryData,
  getConsoleAccountFromCache,
  isConsoleAccountQuerySettled,
  refreshConsoleAccountAfterAuth,
  shouldRevalidateConsoleAccount,
} from '@/lib/react-query/hooks/auth'
import { organizationPlanQueryOptions } from '@/lib/react-query/hooks/organizations'

export function assertMarketingProfileEnabled() {
  if (!getActiveProfileFeatures().marketing) {
    throw redirect({ to: '/', replace: true })
  }
}

async function prefetchOptionalAuthOrganizationPlan(
  queryClient: QueryClient,
  account: Models.User,
) {
  const orgId = account.prefs?.organization as string | undefined
  if (!orgId) return
  await queryClient.ensureQueryData(organizationPlanQueryOptions(orgId))
}

export async function prefetchOptionalAuthHeaderData(
  queryClient: QueryClient,
) {
  if (typeof window === 'undefined') return

  const cachedAccount = getConsoleAccountFromCache(queryClient)
  if (cachedAccount && '$id' in cachedAccount) {
    await prefetchOptionalAuthOrganizationPlan(
      queryClient,
      cachedAccount as Models.User,
    )
    return
  }

  if (isConsoleAccountQuerySettled(queryClient)) {
    return
  }

  if (shouldRevalidateConsoleAccount(queryClient)) {
    try {
      const account = await refreshConsoleAccountAfterAuth(queryClient)
      await prefetchOptionalAuthOrganizationPlan(queryClient, account)
    } catch {
      // Unauthenticated or optional fetch errors - header handles guest state.
    }
    return
  }

  try {
    const account = await ensureConsoleAccountQueryData(queryClient)
    if (!account) return
    await prefetchOptionalAuthOrganizationPlan(queryClient, account)
  } catch {
    // Unauthenticated or optional fetch errors - header handles guest state.
  }
}

export async function marketingPageLoader(queryClient: QueryClient) {
  assertMarketingProfileEnabled()
  await prefetchOptionalAuthHeaderData(queryClient)
}
