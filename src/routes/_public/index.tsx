import {
  createFileRoute,
  redirect,
  isRedirect,
} from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
import { Loader2 } from 'lucide-react'
import { AccountAccessBlockedScreen } from '@/components/global/auth/AccountAccessBlockedScreen'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  resolvePostAuthOrganizationId,
} from '@/lib/ensure-personal-org'
import { prefetchOrganizationOverviewData } from '@/lib/organization-overview-prefetch'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import { searchParamsFromRouterLocation } from '@/lib/table-filters'
import { isHttpForbiddenError } from '@/lib/utils/error-formatting'
import {
  consoleAccountQueryOptions,
  ensureConsoleAccountQueryData,
} from '@/lib/react-query/hooks/auth'

export const Route = createFileRoute('/_public/')({
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') return

    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account) {
      const { queryKey } = consoleAccountQueryOptions()
      const queryError = context.queryClient.getQueryState(queryKey)?.error
      const isMfaRequired =
        queryError instanceof AppwriteException &&
        queryError.type === 'user_more_factors_required'
      const isAccountBlocked =
        !!queryError && isHttpForbiddenError(queryError)
      if (!isMfaRequired && !isAccountBlocked) {
        // Profiles without marketing pages (self-hosted) go straight to sign-in.
        if (!getActiveProfileFeatures().marketing) {
          throw redirect({ to: '/sign-in', replace: true })
        }
        throw redirect({ to: '/home', replace: true })
      }
      return
    }

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

    if (requiresConsoleEmailVerification(account)) {
      throw redirect({ to: '/verify-email', replace: true })
    }

    try {
      const orgId = await resolvePostAuthOrganizationId(
        account,
        context.queryClient,
      )
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
