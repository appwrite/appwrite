import {
  createFileRoute,
  redirect,
  isRedirect,
} from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
import { AccountAccessBlockedScreen } from '@/components/global/auth/AccountAccessBlockedScreen'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import { getHomePageHead, View as HomeView } from '@/components/pages/home/View'
import {
  isOAuthLoginMethod,
  setLastLoginMethod,
} from '@/lib/utils/auth-storage'
import { isPreLaunchModeEnabled } from '@/lib/pre-launch'
import { shouldSkipRootAccountProbe } from '@/lib/console-account-get'
import {
  resolveRootGuestRedirectPathname,
  type RootHomeLoaderData,
} from '@/lib/root-guest-redirect'
import { shouldServerRenderRootHome } from '@/lib/root-home-ssr'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { resolveAndPrefetchDefaultOrganization } from '@/lib/organization-overview-prefetch'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import { prefetchVisitorCountry } from '@/lib/react-query/hooks/locale'
import { searchParamsFromRouterLocation } from '@/lib/table-filters'
import { isHttpForbiddenError } from '@/lib/utils/error-formatting'
import {
  consoleAccountQueryOptions,
  ensureConsoleAccountQueryData,
} from '@/lib/react-query/hooks/auth'

const HOME_VIEW: RootHomeLoaderData = { view: 'home' }
const CONSOLE_VIEW: RootHomeLoaderData = { view: 'console' }

/**
 * Guests see the marketing homepage (server-rendered in production). Signed-in
 * users are routed to the console from the client loader: HttpOnly session
 * cookies are invisible to JS, so `/` renders client-only whenever the request
 * carries a session cookie (or on localhost, where sessions use `cookieFallback`).
 */
export const Route = createFileRoute('/_marketing/')({
  staticData: {
    ...MARKETING_PAGE_ROUTE_STATIC_DATA,
    headerBanner: 'init-org-promo',
  },
  ssr: () => shouldServerRenderRootHome(),
  head: getHomePageHead,
  loader: async ({ context, location }): Promise<RootHomeLoaderData> => {
    const { queryClient } = context

    const showHome = async () => {
      if (typeof window !== 'undefined') {
        const guestRedirect = resolveRootGuestRedirectPathname()
        if (guestRedirect) {
          throw redirect({
            href: `${guestRedirect}${window.location.search}`,
            replace: true,
            reloadDocument: true,
          })
        }
      }
      await prefetchVisitorCountry(queryClient)
      return HOME_VIEW
    }

    // Server only renders `/` for guests (see `shouldServerRenderRootHome`).
    if (typeof window === 'undefined') return showHome()

    // Localhost guests: skip account.get once a guest 401 is cached.
    if (shouldSkipRootAccountProbe()) return showHome()

    const account = await ensureConsoleAccountQueryData(queryClient)
    if (!account) {
      const { queryKey } = consoleAccountQueryOptions()
      const queryError = queryClient.getQueryState(queryKey)?.error
      const isMfaRequired =
        queryError instanceof AppwriteException &&
        queryError.type === 'user_more_factors_required'
      const isAccountBlocked =
        !!queryError && isHttpForbiddenError(queryError)
      if (isMfaRequired || isAccountBlocked) return CONSOLE_VIEW
      return showHome()
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
        queryClient,
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
  component: RootPage,
})

function RootPage() {
  const { view } = Route.useLoaderData()
  const { accountAccessBlocked, isLoading } = useAuth()

  if (view === 'home') return <HomeView />

  if (!isLoading && accountAccessBlocked) {
    return (
      <div className="flex min-h-svh w-full flex-col bg-background">
        <ConsoleImpersonationBanner sessionOnly />
        <AccountAccessBlockedScreen layout="fill" />
      </div>
    )
  }

  // MFA-pending sessions stay on the blank hop until the auth guard routes them.
  return null
}
