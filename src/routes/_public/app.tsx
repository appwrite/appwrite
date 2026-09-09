import {
  createFileRoute,
  redirect,
  isRedirect,
} from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
import { FullscreenLoader } from '@/components/ui/loader'
import { AccountAccessBlockedScreen } from '@/components/global/auth/AccountAccessBlockedScreen'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import {
  isOAuthLoginMethod,
  setLastLoginMethod,
} from '@/lib/utils/auth-storage'
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

export const Route = createFileRoute('/_public/app')({
  ssr: false,
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') return

    if (!hasLikelyConsoleSession()) {
      throw redirect({
        to: resolveRootGuestRedirectPathname(),
        replace: true,
        reloadDocument: true,
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
          reloadDocument: true,
        })
      }
      return
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
  component: ConsoleEntry,
})

function ConsoleEntry() {
  const { accountAccessBlocked, isLoading } = useAuth()

  if (!isLoading && accountAccessBlocked) {
    return (
      <div className="flex min-h-svh w-full flex-col bg-background">
        <ConsoleImpersonationBanner sessionOnly />
        <AccountAccessBlockedScreen layout="fill" />
      </div>
    )
  }

  return <FullscreenLoader />
}
