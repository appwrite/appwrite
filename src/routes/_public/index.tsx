import {
  createFileRoute,
  redirect,
  isRedirect,
} from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
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
import { NOINDEX_ROBOTS_META } from '@/lib/seo/indexing'
import {
  consoleAccountQueryOptions,
  ensureConsoleAccountQueryData,
} from '@/lib/react-query/hooks/auth'

export const Route = createFileRoute('/_public/')({
  // If HTML is ever served (localhost), do not index `/`.
  head: () => ({
    meta: [NOINDEX_ROBOTS_META],
  }),
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') return

    // No readable session: skip account.get and go to /home (or /sign-in).
    // Production document visits never reach this loader (SSR 301). Localhost
    // uses cookieFallback / document.cookie. Console entry is `/app`.
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
  const { accountAccessBlocked, isLoading } = useAuth()

  if (!isLoading && accountAccessBlocked) {
    return (
      <div className="flex min-h-svh w-full flex-col bg-background">
        <ConsoleImpersonationBanner sessionOnly />
        <AccountAccessBlockedScreen layout="fill" />
      </div>
    )
  }

  // Authenticated users are redirected from the loader after org data is prefetched.
  // Production guests never reach this component (SSR 301). Localhost cannot
  // read the session cookie on the server, so keep this outlet empty.
  return null
}
