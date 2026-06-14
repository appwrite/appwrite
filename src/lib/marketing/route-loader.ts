import type { QueryClient } from '@tanstack/react-query'
import { redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  ensureConsoleAccountQueryData,
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

export async function prefetchOptionalAuthHeaderData(
  queryClient: QueryClient,
) {
  if (typeof window === 'undefined') return

  if (shouldRevalidateConsoleAccount(queryClient)) {
    try {
      const account = await refreshConsoleAccountAfterAuth(queryClient)
      const orgId = account.prefs?.organization as string | undefined
      if (orgId) {
        await queryClient.ensureQueryData(organizationPlanQueryOptions(orgId))
      }
    } catch {
      // Unauthenticated or optional fetch errors — header handles guest state.
    }
    return
  }

  if (isConsoleAccountQuerySettled(queryClient)) {
    return
  }

  try {
    const account = await ensureConsoleAccountQueryData(queryClient)
    if (!account) return
    const orgId = account.prefs?.organization as string | undefined
    if (orgId) {
      await queryClient.ensureQueryData(organizationPlanQueryOptions(orgId))
    }
  } catch {
    // Unauthenticated or optional fetch errors — header handles guest state.
  }
}

export async function marketingPageLoader(queryClient: QueryClient) {
  assertMarketingProfileEnabled()
  await prefetchOptionalAuthHeaderData(queryClient)
}
