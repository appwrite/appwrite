import {
  createFileRoute,
  redirect,
  isRedirect,
} from '@tanstack/react-router'
import { Loader2 } from 'lucide-react'
import { AccountAccessBlockedScreen } from '@/components/global/auth/AccountAccessBlockedScreen'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'
import {
  resolvePostAuthOrganizationId,
} from '@/lib/ensure-personal-org'
import { prefetchOrganizationOverviewData } from '@/lib/organization-overview-prefetch'
import { searchParamsFromRouterLocation } from '@/lib/table-filters'
import {
  ensureConsoleAccountQueryData,
} from '@/lib/react-query/hooks/auth'

export const Route = createFileRoute('/_public/')({
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') return

    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account) return

    const urlParams = searchParamsFromRouterLocation(location)
    const isOAuthCallback =
      urlParams.has('project') ||
      urlParams.has('key') ||
      location.pathname.includes('callback')
    if (isOAuthCallback) {
      const hasGitHubIdentity = account.identities?.some(
        (identity) => identity.provider === 'github',
      )
      if (hasGitHubIdentity) {
        setLastLoginMethod('github')
      }
    }

    try {
      const orgId = await resolvePostAuthOrganizationId(account)
      await prefetchOrganizationOverviewData(context.queryClient, orgId)
      throw redirect({
        to: '/organizations/$orgId',
        params: { orgId },
        replace: true,
      })
    } catch (error) {
      if (isRedirect(error)) throw error
      throw redirect({ to: '/account', replace: true })
    }
  },
  component: RootRedirect,
})

function RootRedirect() {
  const { accountAccessBlocked, isLoading, isMfaRequired } = useAuth()

  if (!isLoading && accountAccessBlocked) {
    return (
      <div className="flex min-h-svh w-full flex-col bg-background">
        <ConsoleImpersonationBanner sessionOnly />
        <AccountAccessBlockedScreen layout="fill" />
      </div>
    )
  }

  if (!isLoading && isMfaRequired) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-background">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Authenticated users are redirected from the loader after org data is prefetched.
  // Blank screen while the loader runs; root fullscreen loader covers this route.
  return <div className="fixed inset-0 bg-background" aria-hidden />
}
