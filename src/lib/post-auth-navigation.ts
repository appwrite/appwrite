import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import {
  parseOrganizationIdFromPath,
  prefetchOrganizationOverviewData,
} from '@/lib/organization-overview-prefetch'
import { isHttpNotFoundError } from '@/lib/utils/error-formatting'

function isValidRelativeRedirect(url: string): boolean {
  return url.startsWith('/') && !url.includes('://')
}

async function prefetchOrganizationOverviewSafe(
  queryClient: QueryClient,
  orgId: string,
): Promise<void> {
  try {
    await prefetchOrganizationOverviewData(queryClient, orgId)
  } catch (error) {
    if (!isHttpNotFoundError(error)) throw error
  }
}

/**
 * Prefetch org overview data before navigating after sign-in / sign-up.
 * Never throws for stale org prefs or missing org resources — navigation should
 * still proceed and route loaders can recover.
 */
export async function prefetchPostAuthDestination(
  queryClient: QueryClient,
  account: Models.User,
  redirect?: string,
): Promise<void> {
  if (redirect && isValidRelativeRedirect(redirect)) {
    const orgIdFromPath = parseOrganizationIdFromPath(redirect)
    if (orgIdFromPath) {
      await prefetchOrganizationOverviewSafe(queryClient, orgIdFromPath)
      return
    }
  }

  let orgId = await resolvePostAuthOrganizationId(account)
  try {
    await prefetchOrganizationOverviewData(queryClient, orgId)
  } catch (error) {
    if (!isHttpNotFoundError(error)) return
    orgId = await resolvePostAuthOrganizationId()
    await prefetchOrganizationOverviewSafe(queryClient, orgId)
  }
}
