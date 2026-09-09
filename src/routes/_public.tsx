import { createFileRoute } from '@tanstack/react-router'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'
import { shouldSkipRootAccountProbe } from '@/lib/console-account-get'
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

      // Localhost guests must not wait on account.get. Production `/` HTML is
      // only served when the Cookie header had a session (HttpOnly, so JS
      // cannot see it); still probe account.get there.
      if (location.pathname === '/' && shouldSkipRootAccountProbe()) {
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
