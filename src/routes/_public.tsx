import { createFileRoute } from '@tanstack/react-router'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'

export const Route = createFileRoute('/_public')({
  loader: async ({ context }) => {
    if (typeof window !== 'undefined') {
      const { queryClient } = context
      // Load account (and prefs) before child loaders so e.g. Tables DB rows can
      // match `tableRowsQueryOptions` keys to saved column prefs without a layout shift.
      await queryClient
        .ensureQueryData(consoleAccountQueryOptions())
        .catch(() => {
          // Unauthenticated, MFA, etc. — RequireAuth / useAuth handle UX.
        })
    }
    return {
      currentUser: null,
    }
  },
})
