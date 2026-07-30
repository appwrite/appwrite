import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page'
import {
  parseOrganizationIdFromPath,
  prefetchOrganizationOverviewData,
} from '@/lib/organization-overview-prefetch'
import { isHttpNotFoundError } from '@/lib/utils/error-formatting'

/**
 * Cloud (and any profile with userVerification) requires a verified console
 * email before org/project APIs. Unverified sessions must stay on /verify-email.
 */
export function requiresConsoleEmailVerification(
  account: Pick<Models.User, 'emailVerification'> | null | undefined,
): boolean {
  if (!account) return false
  return (
    getActiveProfileFeatures().userVerification && !account.emailVerification
  )
}

// `/join` is intentionally absent: accepting a team invite requires auth, so it
// is a valid post-auth destination (e.g. after "Switch account" on the invite page).
const AUTH_PAGE_PATHS = [
  '/sign-in',
  '/sign-up',
  '/recovery',
  '/reset',
  '/mfa',
  '/verify-email',
  '/auth/magic-url',
] as const

export function isValidRelativeRedirect(url: string): boolean {
  // Reject protocol-relative URLs (//evil.com) alongside absolute ones.
  return url.startsWith('/') && !url.startsWith('//') && !url.includes('://')
}

function normalizeRedirectPathname(redirect: string): string {
  const pathname = redirect.split('?')[0]?.split('#')[0] ?? redirect
  return pathname.replace(/\/+$/, '') || '/'
}

function isAuthPagePath(pathname: string): boolean {
  const normalized = normalizeRedirectPathname(pathname)
  return (AUTH_PAGE_PATHS as readonly string[]).includes(normalized)
}

// OAuth2 server flows (consent / device). When a user authenticates only to
// authorize an application, we must NOT provision a personal org + project -
// just return them to the flow. On single-tenant profiles org creation also
// throws ("supports only one organization"), which would otherwise abort the
// whole authorization after the account is already created.
const OAUTH2_FLOW_PATHS = ['/oauth2/consent', '/oauth2/device'] as const

export function isOAuth2FlowRedirect(redirect?: string): boolean {
  if (!redirect) return false
  const normalized = normalizeRedirectPathname(redirect)
  return (OAUTH2_FLOW_PATHS as readonly string[]).includes(normalized)
}

/**
 * Returns a post-auth redirect only for console destinations. Marketing pages,
 * auth pages, and `/` fall back to the default org console route.
 */
export function resolvePostAuthRedirect(redirect?: string): string | undefined {
  if (!redirect || !isValidRelativeRedirect(redirect)) return undefined

  const pathname = normalizeRedirectPathname(redirect)
  if (pathname === '/') return undefined
  if (isAuthPagePath(pathname)) return undefined
  if (isMarketingPagePath(pathname)) return undefined

  return redirect
}

/**
 * Split a validated relative redirect into the `{ to, search }` shape TanStack
 * Router needs. Passing a URL with a query string directly as `to` drops the
 * search params - which would lose OAuth2 params like `client_id` (consent) or
 * `user_code` (device) when returning to the flow after sign-up / verification.
 */
export function toRedirectNavigateOptions(redirect: string): {
  to: string
  search: Record<string, string>
} {
  const url = new URL(redirect, 'http://localhost')
  return {
    to: url.pathname,
    search: Object.fromEntries(url.searchParams),
  }
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
 * Never throws for stale org prefs or missing org resources - navigation should
 * still proceed and route loaders can recover.
 */
export async function prefetchPostAuthDestination(
  queryClient: QueryClient,
  account: Models.User,
  redirect?: string,
): Promise<void> {
  // Authorizing an OAuth2 app: skip org provisioning/prefetch entirely.
  if (isOAuth2FlowRedirect(redirect)) return

  const resolvedRedirect = resolvePostAuthRedirect(redirect)
  if (resolvedRedirect) {
    const orgIdFromPath = parseOrganizationIdFromPath(resolvedRedirect)
    if (orgIdFromPath) {
      await prefetchOrganizationOverviewSafe(queryClient, orgIdFromPath)
      return
    }
  }

  let orgId = await resolvePostAuthOrganizationId(account, queryClient)
  try {
    await prefetchOrganizationOverviewData(queryClient, orgId)
  } catch (error) {
    if (!isHttpNotFoundError(error)) return
    orgId = await resolvePostAuthOrganizationId()
    await prefetchOrganizationOverviewSafe(queryClient, orgId)
  }
}
