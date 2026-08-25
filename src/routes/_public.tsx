import { createFileRoute } from '@tanstack/react-router'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'
import { kickoffDefaultOrganizationPrefetch } from '@/lib/organization-overview-prefetch'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import {
  consoleAccountQueryOptions,
  ensureConsoleAccountQueryData,
  isConsoleAccountQuerySettled,
  refreshConsoleAccountAfterAuth,
  shouldRevalidateConsoleAccount,
} from '@/lib/react-query/hooks/auth'

export const Route = createFileRoute('/_public')({
  ssr: false,
  loader: async ({ context, location }) => {
    if (typeof window !== 'undefined') {
      const { queryClient } = context
      const accountQuery = consoleAccountQueryOptions()

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
      const account = await ensureConsoleAccountQueryData(queryClient)

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
