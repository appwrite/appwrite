import { createFileRoute } from '@tanstack/react-router'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'
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
      await ensureConsoleAccountQueryData(queryClient)
    }
    return {
      currentUser: null,
    }
  },
})
