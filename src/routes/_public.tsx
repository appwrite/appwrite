import { createFileRoute } from '@tanstack/react-router'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'

export const Route = createFileRoute('/_public')({
  ssr: false,
  loader: async ({ context, location }) => {
    if (typeof window !== 'undefined') {
      const { queryClient } = context
      const accountQuery = consoleAccountQueryOptions()

      if (isOptionalAuthPage(location.pathname)) {
        // Init is public-first: render immediately; account loads in the background.
        void queryClient.prefetchQuery(accountQuery).catch(() => {})
        return { currentUser: null }
      }

      // Load account (and prefs) before child loaders so e.g. Tables DB rows can
      // match `tableRowsQueryOptions` keys to saved column prefs without a layout shift.
      await queryClient.ensureQueryData(accountQuery).catch(() => {
        // Unauthenticated, MFA, etc. — RequireAuth / useAuth handle UX.
      })
    }
    return {
      currentUser: null,
    }
  },
})
