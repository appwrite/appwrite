import {
  createFileRoute,
  useNavigate,
  useLocation,
} from '@tanstack/react-router'
import { useEffect, useRef } from 'react'
import { AccountAccessBlockedScreen } from '@/components/global/auth/AccountAccessBlockedScreen'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'
import { ensurePersonalOrgAndFirstProject } from '@/lib/ensure-personal-org'

export const Route = createFileRoute('/_public/')({
  component: RootRedirect,
})

function RootRedirect() {
  const { account, accountAccessBlocked, isLoading } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const hasRedirectedRef = useRef(false)
  const isEnsuringOrgRef = useRef(false)

  useEffect(() => {
    if (hasRedirectedRef.current) return

    if (accountAccessBlocked) return

    if (!account) return

    // OAuth callback: persist GitHub as last login method
    const urlParams = new URLSearchParams(location.search)
    const isOAuthCallback =
      urlParams.has('project') ||
      urlParams.has('key') ||
      location.pathname.includes('callback')
    if (isOAuthCallback && account) {
      const hasGitHubIdentity = account.identities?.some(
        (identity: unknown) => identity.provider === 'github',
      )
      if (hasGitHubIdentity) {
        setLastLoginMethod('github')
      }
    }

    const orgId = account.prefs?.organization as string | undefined

    if (orgId) {
      hasRedirectedRef.current = true
      navigate({
        to: '/organizations/$orgId',
        params: { orgId },
        replace: true,
      })
      return
    }

    // No org in prefs: ensure personal org + first project, then redirect to org
    if (isEnsuringOrgRef.current) return
    isEnsuringOrgRef.current = true
    ensurePersonalOrgAndFirstProject()
      .then((newOrgId) => {
        hasRedirectedRef.current = true
        navigate({
          to: '/organizations/$orgId',
          params: { orgId: newOrgId },
          replace: true,
        })
      })
      .catch(() => {
        hasRedirectedRef.current = true
        navigate({ to: '/account', replace: true })
      })
  }, [
    account,
    accountAccessBlocked,
    navigate,
    location.pathname,
    location.search,
  ])

  if (!isLoading && accountAccessBlocked) {
    return (
      <div className="flex min-h-svh w-full flex-col bg-background">
        <ConsoleImpersonationBanner sessionOnly />
        <AccountAccessBlockedScreen layout="fill" />
      </div>
    )
  }

  // Blank screen while redirecting - root shows branded loader; never show "Loading..." here
  return <div className="fixed inset-0 bg-background" aria-hidden />
}
