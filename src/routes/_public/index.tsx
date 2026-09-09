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
import {
  isOAuthLoginMethod,
  setLastLoginMethod,
} from '@/lib/utils/auth-storage'
import { isPreLaunchModeEnabled } from '@/lib/pre-launch'
import { hasLikelyConsoleSession } from '@/lib/console-account-get'
import { resolveRootGuestRedirectPathname } from '@/lib/root-guest-redirect'
import { resolveAndPrefetchDefaultOrganization } from '@/lib/organization-overview-prefetch'
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

    // No session signal: skip account.get and redirect immediately (in-app nav).
    // Full-page guest visits are handled by rootGuestRedirectMiddleware (302).
    if (!hasLikelyConsoleSession()) {
      throw redirect({
        to: resolveRootGuestRedirectPathname(),
        replace: true,
      })
    }

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
        throw redirect({
          to: resolveRootGuestRedirectPathname(),
          replace: true,
        })
      }
      return
    }

    if (isPreLaunchModeEnabled()) {
      throw redirect({ to: '/init', replace: true })
    }

    const urlParams = searchParamsFromRouterLocation(location)
    const isOAuthCallback =
      urlParams.has('project') ||
      urlParams.has('key') ||
      location.pathname.includes('callback')
    if (isOAuthCallback) {
      const oauthIdentity = account.identities?.find((identity) =>
        isOAuthLoginMethod(identity.provider),
      )
      if (oauthIdentity) {
        setLastLoginMethod(oauthIdentity.provider)
      }
    }

    if (requiresConsoleEmailVerification(account)) {
      throw redirect({ to: '/verify-email', replace: true })
    }

    try {
      const orgId = await resolveAndPrefetchDefaultOrganization(
        context.queryClient,
        account,
      )
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
