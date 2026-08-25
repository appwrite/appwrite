import { useLoaderData, useNavigate, useLocation } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { clearConsoleSessionLocally } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import { ReactNode, useEffect, useRef, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { useConsoleImpersonationRevision } from '@/hooks/use-console-impersonation-revision'
import { isHttpForbiddenError } from '@/lib/utils/error-formatting'
import {
  consoleAccountQueryOptions,
  isConsoleSigningOut,
  performConsoleSignOut,
} from '@/lib/react-query/hooks/auth'
import { AccountAccessBlockedScreen } from '@/components/global/auth/AccountAccessBlockedScreen'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import {
  applyScreenshotModeAccount,
  subscribeScreenshotMode,
} from '@/lib/screenshot-mode'

// Helper function to check if we're on an auth page
function isAuthPage(pathname: string): boolean {
  return (
    pathname === '/sign-in' ||
    pathname === '/sign-up' ||
    pathname === '/recovery' ||
    pathname === '/reset' ||
    pathname === '/join' ||
    pathname === '/mfa' ||
    pathname === '/verify-email' ||
    pathname === '/auth/magic-url'
  )
}

/** Console routes that work without sign-in; account is optional. */
export function isOptionalAuthPage(pathname: string): boolean {
  const features = getActiveProfileFeatures()
  // Soft-launch password gate; guests land here before marketing/console.
  if (pathname === '/access') return true
  if (pathname === '/init') return features.init
  // Legacy `/agent` / `/assistant` still soft-auth while they redirect into org scope.
  if (
    pathname === '/agent' ||
    pathname.startsWith('/agent/') ||
    pathname === '/assistant' ||
    pathname.startsWith('/assistant/')
  ) {
    // Keep MCP OAuth callback under normal auth handling.
    if (
      pathname.startsWith('/agent/mcp/') ||
      pathname.startsWith('/assistant/mcp/')
    ) {
      return false
    }
    return features.agent
  }
  // Debug demos must stay reachable without auth redirects (and without
  // signing the user out via linked auth routes).
  if (pathname.startsWith('/debug/')) return true
  return isMarketingPagePath(pathname)
}

// Helper function to extract redirect from search params
function extractRedirectFromSearch(
  search: string | URLSearchParams | undefined,
): string | null {
  if (!search) return null

  const searchParams =
    search instanceof URLSearchParams
      ? search
      : new URLSearchParams(typeof search === 'string' ? search : '')

  const redirect = searchParams.get('redirect')
  return redirect && redirect.startsWith('/') && !redirect.includes('://')
    ? redirect
    : null
}

// Helper function to get relative redirect URL from current location
// If we're already on an auth page, extract the original redirect from search params
function getRelativeRedirectUrl(location: unknown): string | null {
  // If we're already on an auth page, extract the original redirect from search params
  if (isAuthPage(location.pathname)) {
    // Handle TanStack Router's parsed search params
    if (
      location.search &&
      typeof location.search === 'object' &&
      'redirect' in location.search
    ) {
      const redirect = location.search.redirect
      return redirect &&
        typeof redirect === 'string' &&
        isValidRelativeRedirect(redirect)
        ? redirect
        : null
    }
    // Fallback to string/URLSearchParams handling
    return extractRedirectFromSearch(
      location.search as string | URLSearchParams | undefined,
    )
  }

  // Otherwise, use the current location as redirect
  // Build search string from parsed params or raw string
  let searchStr = ''
  if (location.search) {
    if (typeof location.search === 'string') {
      searchStr = location.search
    } else if (location.search instanceof URLSearchParams) {
      searchStr = location.search.toString()
    } else if (typeof location.search === 'object') {
      // Convert parsed search object to query string
      const params = new URLSearchParams()
      Object.entries(location.search).forEach(([key, value]) => {
        if (value !== undefined && value !== null) {
          params.set(key, String(value))
        }
      })
      searchStr = params.toString()
    }
  }

  const redirectUrl = `${location.pathname}${searchStr ? `?${searchStr}` : ''}`

  // Validate that the redirect is relative (prevents redirect hijacking)
  if (redirectUrl.startsWith('/') && !redirectUrl.includes('://')) {
    return redirectUrl
  }

  return null
}

// Helper function to validate that a redirect URL is relative (prevents redirect hijacking)
function isValidRelativeRedirect(url: string): boolean {
  try {
    // Must start with / and not contain :// (which would indicate a protocol)
    return url.startsWith('/') && !url.includes('://')
  } catch {
    return false
  }
}

type RouterLocation = ReturnType<typeof useLocation>

const AUTH_REDIRECT_DEDUPE_WINDOW_MS = 750
let lastAuthRedirectKey: string | null = null
let lastAuthRedirectAtMs = 0

function shouldSkipDuplicateAuthRedirect(key: string): boolean {
  if (typeof window === 'undefined') return false
  const now = Date.now()
  if (
    lastAuthRedirectKey === key &&
    now - lastAuthRedirectAtMs < AUTH_REDIRECT_DEDUPE_WINDOW_MS
  ) {
    return true
  }
  lastAuthRedirectKey = key
  lastAuthRedirectAtMs = now
  return false
}

/**
 * Navigate to MFA or sign-in when the account query fails. Must run in useEffect -
 * never call navigate from inside queryFn (async updates before mount).
 */
function useAuthErrorNavigation(error: unknown, location: RouterLocation) {
  const navigate = useNavigate()
  const navigateRef = useRef(navigate)
  navigateRef.current = navigate

  const needsMfa =
    error instanceof AppwriteException &&
    error.type === 'user_more_factors_required'

  useEffect(() => {
    // Sign-out uses a hard redirect; SPA MFA navigation would flash under it.
    if (isConsoleSigningOut()) return
    if (!needsMfa || location.pathname === '/mfa') return
    const redirectUrl = getRelativeRedirectUrl(location as unknown)
    const redirectKey = `mfa:${redirectUrl ?? ''}`
    if (shouldSkipDuplicateAuthRedirect(redirectKey)) return
    if (redirectUrl && isValidRelativeRedirect(redirectUrl)) {
      navigateRef.current({ to: '/mfa', search: { redirect: redirectUrl } })
    } else {
      navigateRef.current({ to: '/mfa' })
    }
  }, [needsMfa, location.pathname])

  const is401 =
    !!error &&
    ((error as { code?: number }).code === 401 ||
      (error as { status?: number }).status === 401)

  useEffect(() => {
    // Sign-out covers the viewport and hard-navigates to /sign-in. Do not SPA
    // navigate here or the console will flash empty/guest states mid-logout.
    if (isConsoleSigningOut()) return
    if (
      !is401 ||
      isAuthPage(location.pathname) ||
      isOptionalAuthPage(location.pathname)
    ) {
      return
    }
    const redirectUrl = getRelativeRedirectUrl(location as unknown)
    const redirectKey = `signin:${redirectUrl ?? ''}`
    if (shouldSkipDuplicateAuthRedirect(redirectKey)) return
    if (redirectUrl && isValidRelativeRedirect(redirectUrl)) {
      navigateRef.current({ to: '/sign-in', search: { redirect: redirectUrl } })
    } else {
      navigateRef.current({ to: '/sign-in' })
    }
  }, [is401, location.pathname])
}

export interface AuthData {
  currentUser: unknown
  account: unknown
  isLoading: boolean
  /** True until the account query has settled (including before client-only fetch starts). */
  isPending: boolean
  /** True after the account query has completed at least once (success or error). */
  isFetched: boolean
  isAuthenticated: boolean
  isMfaRequired: boolean
  /** Present when `account.get` returned 403 (blocked / forbidden console access). */
  accountAccessBlocked?: boolean
  signOut: (navigate?: (options: { to: string }) => void) => Promise<void>
}

function isMfaRequiredError(error: unknown) {
  return (
    error instanceof AppwriteException &&
    error.type === 'user_more_factors_required'
  )
}

// Client-side sign out - always hard-redirects to sign-in when complete.
async function signOut(
  _navigate?: (options: { to: string }) => void,
  queryClient?: ReturnType<typeof useQueryClient>,
) {
  if (queryClient) {
    await performConsoleSignOut(queryClient)
    return
  }

  clearConsoleSessionLocally()
  if (typeof window !== 'undefined') {
    window.location.replace('/sign-in')
  }
}

interface RequireAuthProps {
  children: ReactNode | ((auth: AuthData) => ReactNode)
  fallback?: ReactNode
  loadingComponent?: ReactNode
}

/**
 * Component wrapper that ensures the user is authenticated before rendering children.
 *
 * - Automatically checks authentication using the Console SDK
 * - Redirects to /sign-in on 401 errors
 * - On 403, shows the blocked-account screen with support contact (no redirect to /sign-in)
 * - Shows loading state while checking auth
 * - Only renders children if authenticated
 *
 * Supports two patterns:
 * 1. Simple wrapper (children as ReactNode)
 * 2. Render prop (children as function receiving auth data)
 *
 * @example
 * ```tsx
 * // Simple wrapper
 * function MyPage() {
 *   return (
 *     <RequireAuth>
 *       <MyProtectedContent />
 *     </RequireAuth>
 *   )
 * }
 *
 * // Render prop (when you need auth data)
 * function MyPage() {
 *   return (
 *     <RequireAuth>
 *       {({ account, isAuthenticated }) => (
 *         <div>Welcome {account.name}</div>
 *       )}
 *     </RequireAuth>
 *   )
 * }
 * ```
 */
export function RequireAuth({
  children,
  fallback = null,
  loadingComponent = null,
}: RequireAuthProps) {
  const { currentUser } = useLoaderData({ from: '__root__' })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const consoleImpersonationRevision = useConsoleImpersonationRevision()

  // Client-side authentication check using Console SDK
  const {
    data: accountData,
    isLoading,
    isPending,
    isFetched,
    error,
  } = useQuery(
    consoleAccountQueryOptions({ revision: consoleImpersonationRevision }),
  )

  const [, setScreenshotModeEpoch] = useState(0)
  useEffect(() => {
    return subscribeScreenshotMode(() => {
      setScreenshotModeEpoch((epoch) => epoch + 1)
    })
  }, [])

  const account = applyScreenshotModeAccount(accountData)

  useAuthErrorNavigation(error, location)

  const accountAccessBlocked = !!error && isHttpForbiddenError(error)
  const isMfaRequired = isMfaRequiredError(error)
  const isAuthenticated = !!account && !error
  const needsEmailVerification =
    isAuthenticated && requiresConsoleEmailVerification(accountData)

  // Unverified console accounts cannot use org/project APIs. Keep them on
  // /verify-email (with a return path) instead of rendering a broken console.
  useEffect(() => {
    if (!needsEmailVerification) return
    if (location.pathname === '/verify-email') return
    if (isAuthPage(location.pathname) || isOptionalAuthPage(location.pathname)) {
      return
    }
    const redirectUrl = getRelativeRedirectUrl(location as unknown)
    if (redirectUrl && isValidRelativeRedirect(redirectUrl)) {
      navigate({
        to: '/verify-email',
        search: { redirect: redirectUrl },
        replace: true,
      })
    } else {
      navigate({ to: '/verify-email', replace: true })
    }
  }, [needsEmailVerification, location.pathname, location.search, navigate])

  const authData: AuthData = {
    currentUser,
    account,
    isLoading,
    isPending,
    isFetched,
    isAuthenticated,
    isMfaRequired,
    accountAccessBlocked,
    signOut: () => signOut(navigate, queryClient),
  }

  // Show loading state while checking auth
  if (isLoading) {
    return <>{loadingComponent}</>
  }

  if (accountAccessBlocked) {
    return (
      <div className="flex min-h-svh w-full flex-col bg-background">
        <ConsoleImpersonationBanner sessionOnly />
        <AccountAccessBlockedScreen layout="fill" />
      </div>
    )
  }

  // If not authenticated, the hook will redirect to /sign-in
  // But we can also show a fallback here
  if (!isAuthenticated) {
    if (isMfaRequired && location.pathname !== '/mfa') {
      return (
        loadingComponent ?? (
          <div className="flex min-h-svh items-center justify-center bg-background">
            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
          </div>
        )
      )
    }
    return <>{fallback}</>
  }

  if (
    needsEmailVerification &&
    location.pathname !== '/verify-email' &&
    !isAuthPage(location.pathname) &&
    !isOptionalAuthPage(location.pathname)
  ) {
    return (
      loadingComponent ?? (
        <div className="flex min-h-svh items-center justify-center bg-background">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      )
    )
  }

  // User is authenticated - render children
  // Support both regular children and render prop pattern
  return <>{typeof children === 'function' ? children(authData) : children}</>
}

/**
 * Hook version for cases where you need auth data but don't need the wrapper component.
 * This is just a re-export of the internal logic for convenience.
 *
 * @example
 * ```tsx
 * function MyComponent() {
 *   const { account, isLoading, isAuthenticated } = useAuth()
 *
 *   if (isLoading) return <Loading />
 *   if (!isAuthenticated) return null
 *
 *   return <div>Welcome {account.name}</div>
 * }
 * ```
 */
export function useAuth(): AuthData {
  const { currentUser } = useLoaderData({ from: '__root__' })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const consoleImpersonationRevision = useConsoleImpersonationRevision()
  const [, setScreenshotModeEpoch] = useState(0)

  useEffect(() => {
    return subscribeScreenshotMode(() => {
      setScreenshotModeEpoch((epoch) => epoch + 1)
    })
  }, [])

  const {
    data: accountData,
    isLoading,
    isPending,
    isFetched,
    error,
  } = useQuery(
    consoleAccountQueryOptions({ revision: consoleImpersonationRevision }),
  )

  useAuthErrorNavigation(error, location)

  const accountAccessBlocked = !!error && isHttpForbiddenError(error)
  const account = applyScreenshotModeAccount(accountData)

  return {
    currentUser,
    account,
    isLoading,
    isPending,
    isFetched,
    isAuthenticated: !!account && !error,
    isMfaRequired: isMfaRequiredError(error),
    accountAccessBlocked,
    signOut: () => signOut(navigate, queryClient),
  }
}
