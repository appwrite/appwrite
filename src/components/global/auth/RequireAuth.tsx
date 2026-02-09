import { useLoaderData, useNavigate, useLocation } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import { ReactNode } from 'react'

// Helper function to check if we're on an auth page
function isAuthPage(pathname: string): boolean {
  return (
    pathname === '/sign-in' ||
    pathname === '/sign-up' ||
    pathname === '/recovery' ||
    pathname === '/reset' ||
    pathname === '/join' ||
    pathname === '/mfa'
  )
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

export interface AuthData {
  currentUser: unknown
  account: unknown
  isLoading: boolean
  isAuthenticated: boolean
  signOut: (navigate?: (options: { to: string }) => void) => Promise<void>
}

// Client-side sign out function - deletes only the current session
async function signOut(navigate?: (options: { to: string }) => void) {
  try {
    // Get all sessions to find the current one
    const sessionsResponse = await sdk.forConsole.account.listSessions()
    const sessions = sessionsResponse.sessions || []

    // Find the current session
    const currentSession = sessions.find((session) => session.current === true)

    if (currentSession) {
      // Delete only the current session
      await sdk.forConsole.account.deleteSession({
        sessionId: currentSession.$id,
      })
    } else {
      // Fallback: if no current session found, delete all sessions
      await sdk.forConsole.account.deleteSessions()
    }

    // Redirect to sign-in after successful sign out
    if (navigate) {
      navigate({ to: '/sign-in' })
    } else if (typeof window !== 'undefined') {
      window.location.href = '/sign-in'
    }
  } catch (error) {
    console.error('Error signing out:', error)
    throw error
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

  // Client-side authentication check using Console SDK
  const {
    data: account,
    isLoading,
    error,
  } = useQuery({
    queryKey: ['account', 'console'],
    queryFn: async () => {
      try {
        const accountData = await sdk.forConsole.account.get()
        return accountData
      } catch (err) {
        // Handle MFA requirement - redirect to MFA page
        if (
          err instanceof AppwriteException &&
          err.type === 'user_more_factors_required'
        ) {
          // Don't redirect if we're already on the MFA page
          if (location.pathname === '/mfa') {
            throw err
          }
          const redirectUrl = getRelativeRedirectUrl(location as unknown)
          if (redirectUrl && isValidRelativeRedirect(redirectUrl)) {
            navigate({ to: '/mfa', search: { redirect: redirectUrl } })
          } else {
            navigate({ to: '/mfa' })
          }
          throw err
        }

        // Handle 401 Unauthorized - redirect to sign-in with current location as redirect
        // But don't redirect if we're already on an auth page (prevents loops)
        if (isAuthPage(location.pathname)) {
          // Already on auth page, just throw the error without redirecting
          throw err
        }

        const redirectUrl = getRelativeRedirectUrl(location as unknown)
        if (err instanceof AppwriteException && err.code === 401) {
          if (redirectUrl && isValidRelativeRedirect(redirectUrl)) {
            navigate({ to: '/sign-in', search: { redirect: redirectUrl } })
          } else {
            navigate({ to: '/sign-in' })
          }
          throw err
        }
        // Also check for status code in case it's not an AppwriteException
        if (
          (err as unknown)?.code === 401 ||
          (err as unknown)?.status === 401
        ) {
          if (redirectUrl && isValidRelativeRedirect(redirectUrl)) {
            navigate({ to: '/sign-in', search: { redirect: redirectUrl } })
          } else {
            navigate({ to: '/sign-in' })
          }
          throw err
        }
        throw err
      }
    },
    retry: false,
    staleTime: 5 * 60 * 1000, // 5 minutes
    enabled: typeof window !== 'undefined', // Only run on client side
    refetchOnMount: true, // Refetch when component mounts
  })

  const isAuthenticated = !!account && !error
  const authData: AuthData = {
    currentUser,
    account,
    isLoading,
    isAuthenticated,
    signOut: () => signOut(navigate),
  }

  // Show loading state while checking auth
  if (isLoading) {
    return <>{loadingComponent}</>
  }

  // If not authenticated, the hook will redirect to /sign-in
  // But we can also show a fallback here
  if (!isAuthenticated) {
    return <>{fallback}</>
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

  const {
    data: account,
    isLoading,
    error,
  } = useQuery({
    queryKey: ['account', 'console'],
    queryFn: async () => {
      try {
        return await sdk.forConsole.account.get()
      } catch (err) {
        // Handle MFA requirement - redirect to MFA page
        if (
          err instanceof AppwriteException &&
          err.type === 'user_more_factors_required'
        ) {
          // Don't redirect if we're already on the MFA page
          if (location.pathname === '/mfa') {
            throw err
          }
          const redirectUrl = getRelativeRedirectUrl(location as unknown)
          if (redirectUrl && isValidRelativeRedirect(redirectUrl)) {
            navigate({ to: '/mfa', search: { redirect: redirectUrl } })
          } else {
            navigate({ to: '/mfa' })
          }
          throw err
        }

        // Handle 401 Unauthorized - redirect to sign-in with current location as redirect
        // But don't redirect if we're already on an auth page (prevents loops)
        if (isAuthPage(location.pathname)) {
          // Already on auth page, just throw the error without redirecting
          throw err
        }

        const redirectUrl = getRelativeRedirectUrl(location as unknown)
        if (err instanceof AppwriteException && err.code === 401) {
          if (redirectUrl && isValidRelativeRedirect(redirectUrl)) {
            navigate({ to: '/sign-in', search: { redirect: redirectUrl } })
          } else {
            navigate({ to: '/sign-in' })
          }
          throw err
        }
        if (
          (err as unknown)?.code === 401 ||
          (err as unknown)?.status === 401
        ) {
          if (redirectUrl && isValidRelativeRedirect(redirectUrl)) {
            navigate({ to: '/sign-in', search: { redirect: redirectUrl } })
          } else {
            navigate({ to: '/sign-in' })
          }
          throw err
        }
        throw err
      }
    },
    retry: false,
    staleTime: 5 * 60 * 1000,
    enabled: typeof window !== 'undefined',
    refetchOnMount: true,
  })

  return {
    currentUser,
    account,
    isLoading,
    isAuthenticated: !!account && !error,
    signOut: () => signOut(navigate),
  }
}
