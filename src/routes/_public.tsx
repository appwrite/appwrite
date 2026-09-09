import { createFileRoute } from '@tanstack/react-router'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'
import { hasLikelyConsoleSession } from '@/lib/console-account-get'
import { kickoffDefaultOrganizationPrefetch } from '@/lib/organization-overview-prefetch'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import {
  consoleAccountQueryOptions,
  ensureConsoleAccountQueryData,
  isConsoleAccountQuerySettled,
  refreshConsoleAccountAfterAuth,
  shouldRevalidateConsoleAccount,
} from '@/lib/react-query/hooks/auth'
import { consoleVariablesQueryOptions } from '@/lib/react-query/hooks/console-variables'

export const Route = createFileRoute('/_public')({
  ssr: false,
  loader: async ({ context, location }) => {
    if (typeof window !== 'undefined') {
      const { queryClient } = context
      const accountQuery = consoleAccountQueryOptions()

      // Guest `/` must not wait on account.get. That fetch delayed the client
      // hop and let marketing chrome paint around an empty outlet.
      if (location.pathname === '/' && !hasLikelyConsoleSession()) {
        return { currentUser: null }
      }

      if (isOptionalAuthPage(location.pathname)) {
        if (shouldRevalidateConsoleAccount(queryClient)) {
          void refreshConsoleAccountAfterAuth(queryClient).catch(() => {})
        } else if (!isConsoleAccountQuerySettled(queryClient)) {
          void queryClient.prefetchQuery(accountQuery).catch(() => {})
        }
        return { currentUser: null }
      }

      // Load account (and prefs) before child loaders so e.g. Tables DB rows can
      // match `tableRowsQueryOptions` keys to saved column prefs without a layout shift.
      // Console variables gate self-hosted usage stats; resolve them here so the
      // org projects list can reserve chart space on first paint.
      const [account] = await Promise.all([
        ensureConsoleAccountQueryData(queryClient),
        queryClient
          .ensureQueryData(consoleVariablesQueryOptions())
          .catch(() => {}),
      ])

      // `/` still has to resolve + redirect, but the overview fetches can start
      // as soon as we know the preferred org from prefs.
      if (
        account &&
        location.pathname === '/' &&
        !requiresConsoleEmailVerification(account)
      ) {
        kickoffDefaultOrganizationPrefetch(queryClient, account)
      }
    }
    return {
      currentUser: null,
    }
  },
})
