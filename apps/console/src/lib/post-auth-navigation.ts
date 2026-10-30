import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { isPreLaunchModeEnabled } from '@/lib/pre-launch'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page'
import {
  parseOrganizationIdFromPath,
  prefetchOrganizationOverviewData,
  resolveAndPrefetchDefaultOrganization,
} from '@/lib/organization-overview-prefetch'
import { isHttpNotFoundError } from '@/lib/utils/error-formatting'
import { EDUCATION_JOIN_PATH } from '@/lib/education/paths'
import { organizationsQueryOptions } from '@/lib/react-query/hooks/organizations'
import {
  getAuthEmailSearch,
  getAuthSignInPath,
  resolveAuthAudience,
  type AuthAudience,
} from '@/lib/auth-audience'
import { organizationHomeLink } from '@/lib/partners'

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

// `/join` is intentionally absent: invitations work with or without a session,
// so they remain valid destinations after signing in.
const AUTH_PAGE_PATHS = [
  '/sign-in',
  '/sign-up',
  '/recovery',
  '/reset',
  '/mfa',
  '/verify-email',
  '/auth/magic-url',
  '/partner/sign-in',
  '/partner/sign-up',
] as const

export function isValidRelativeRedirect(value: string): boolean {
  if (
    !value.startsWith('/') ||
    value.startsWith('//') ||
    /[\\\u0000-\u001f\u007f]/.test(value)
  ) {
    return false
  }
  try {
    // Validate destination structure, not URLs embedded in query values.
    // Callers must retain the original bytes rather than the normalized URL.
    const origin = 'https://console.invalid'
    const destination = new URL(value, origin)
    const pathname = decodeURIComponent(destination.pathname)
    return (
      destination.origin === origin &&
      !pathname.startsWith('//') &&
      !/[\\\u0000-\u001f\u007f]/.test(pathname)
    )
  } catch {
    return false
  }
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
  if (isPreLaunchModeEnabled()) return '/init'
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
export function toRedirectNavigateOptions(
  redirect: string,
):
  | { to: string; search: Record<string, string> }
  | { href: string; reloadDocument: true } {
  const url = new URL(redirect, 'http://localhost')
  if (
    isValidRelativeRedirect(redirect) &&
    url.origin === 'http://localhost' &&
    (url.pathname === '/oauth2/consent' || url.pathname === '/auth/preview')
  ) {
    // Native raw requests must not pass through parsed search serialization:
    // it collapses repeated resources and quotes JSON-like state/RAR strings
    // and custom project IDs like `1e3`, which these pages read as sent.
    // href without to uses the installed router's direct document navigation.
    return { href: redirect, reloadDocument: true }
  }
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
  // Education enrollment provisions its own organization after verifying GitHub.
  if (redirect && normalizeRedirectPathname(redirect) === EDUCATION_JOIN_PATH) {
    return
  }
  if (isPreLaunchModeEnabled()) return

  const resolvedRedirect = resolvePostAuthRedirect(redirect)
  if (resolvedRedirect) {
    const orgIdFromPath = parseOrganizationIdFromPath(resolvedRedirect)
    if (orgIdFromPath) {
      await prefetchOrganizationOverviewSafe(queryClient, orgIdFromPath)
      return
    }
  }

  if (resolveAuthAudience() === 'partner') {
    await queryClient.ensureQueryData(organizationsQueryOptions()).catch(() => {})
    return
  }

  try {
    await resolveAndPrefetchDefaultOrganization(queryClient, account)
  } catch (error) {
    if (!isHttpNotFoundError(error)) return
    const orgId = await resolvePostAuthOrganizationId()
    await prefetchOrganizationOverviewSafe(queryClient, orgId)
  }
}

type PostAuthNavigate = (
  opts:
    | {
        to: string
        params?: Record<string, string | undefined>
        search?: Record<string, string>
        replace?: boolean
      }
    | { href: string; reloadDocument?: boolean; replace?: boolean },
) => unknown

export async function resolvePostAuthHomeTarget(
  account: Models.User,
  queryClient: QueryClient,
  options?: { redirect?: string; audience?: AuthAudience },
) {
  const targetRedirect = resolvePostAuthRedirect(options?.redirect)
  if (targetRedirect) {
    return { ...toRedirectNavigateOptions(targetRedirect), replace: true as const }
  }

  const audience = options?.audience ?? resolveAuthAudience()
  const orgId = await resolvePostAuthOrganizationId(account, queryClient, {
    audience,
  })
  return {
    ...organizationHomeLink(orgId, audience === 'partner'),
    replace: true as const,
  }
}

/**
 * Send the user to their console or partner home after a successful auth step.
 */
export async function finishPostAuthNavigation(
  navigate: PostAuthNavigate,
  account: Models.User,
  queryClient: QueryClient,
  options?: { redirect?: string; audience?: AuthAudience },
): Promise<void> {
  navigate(await resolvePostAuthHomeTarget(account, queryClient, options))
}

export function authSignInNavigateTarget(audience?: AuthAudience) {
  const resolved = audience ?? resolveAuthAudience()
  return {
    to: getAuthSignInPath(resolved),
    search: getAuthEmailSearch(resolved),
  }
}
