import {
  createFileRoute,
  useNavigate,
  useLocation,
} from '@tanstack/react-router'
import { useEffect, useRef } from 'react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'
import { LOADER_BG } from '@/components/ui/loader'

export const Route = createFileRoute('/_public/')({
  component: RootRedirect,
})

function RootRedirect() {
  const { account } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const hasRedirectedRef = useRef(false)

  useEffect(() => {
    // Prevent multiple redirects
    if (hasRedirectedRef.current) {
      return
    }

    if (!account) return // Wait for auth

    // Check if this is an OAuth callback (URL might contain OAuth params)
    // If we have an account and came from OAuth flow, ensure GitHub method is saved
    const urlParams = new URLSearchParams(location.search)
    const isOAuthCallback =
      urlParams.has('project') ||
      urlParams.has('key') ||
      location.pathname.includes('callback')

    // If we detect OAuth callback and account exists, save GitHub method
    if (isOAuthCallback && account) {
      // Check if account has GitHub identity
      const hasGitHubIdentity = account.identities?.some(
        (identity: unknown) => identity.provider === 'github',
      )
      if (hasGitHubIdentity) {
        setLastLoginMethod('github')
      }
    }

    // Get org ID from account prefs
    const orgId = account.prefs?.organization as string | undefined

    // Mark as redirected before navigating to prevent loops
    hasRedirectedRef.current = true

    if (orgId) {
      navigate({
        to: '/organizations/$orgId',
        params: { orgId },
        replace: true,
      })
      return
    }

    // Fallback to onboarding if no organization preference
    navigate({
      to: '/onboarding',
      replace: true,
    })
  }, [account, navigate, location.pathname, location.search])

  // Blank screen while redirecting — root shows branded loader; never show "Loading..." here
  return (
    <div
      className="fixed inset-0"
      style={{ backgroundColor: LOADER_BG }}
      aria-hidden
    />
  )
}
