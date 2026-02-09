import { createFileRoute } from '@tanstack/react-router'

// Helper function to check if we're on an auth page
function isAuthPage(pathname: string): boolean {
  return (
    pathname === '/sign-in' ||
    pathname === '/sign-up' ||
    pathname === '/recover' ||
    pathname === '/join'
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

// Helper function to validate that a redirect URL is relative (prevents redirect hijacking)
function isValidRelativeRedirect(url: string): boolean {
  try {
    // Must start with / and not contain :// (which would indicate a protocol)
    return url.startsWith('/') && !url.includes('://')
  } catch {
    return false
  }
}

export const Route = createFileRoute('/_protected')({
  loader: async () => {
    // Client-side authentication is handled by RequireAuth component
    // Return null for currentUser - it will be fetched client-side
    // RequireAuth will handle redirects to sign-in if not authenticated
    return {
      currentUser: null,
    }
  },
})
