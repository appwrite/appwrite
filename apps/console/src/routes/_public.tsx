import { createFileRoute } from '@tanstack/react-router'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'
import { ensureConsoleAccountQueryData } from '@/lib/react-query/hooks/auth'
import { prefetchOptionalAuthHeaderData } from '@/lib/marketing/route-loader'
import { consoleVariablesQueryOptions } from '@/lib/react-query/hooks/console-variables'

export const Route = createFileRoute('/_public')({
  ssr: false,
  // Child routes guard on feature flags in `beforeLoad`, which runs before any
  // loader. Account labels switch flags on, so the account must load first.
  beforeLoad: async ({ context, location }) => {
    if (typeof window === 'undefined') return
    if (isOptionalAuthPage(location.pathname)) return
    await ensureConsoleAccountQueryData(context.queryClient)
  },
  loader: async ({ context, location }) => {
    if (typeof window !== 'undefined') {
      const { queryClient } = context

      if (isOptionalAuthPage(location.pathname)) {
        // Reuse settled guest state so link preloads do not reset the header.
        await prefetchOptionalAuthHeaderData(queryClient)
        return { currentUser: null }
      }

      // Load account (and prefs) before child loaders so e.g. Tables DB rows can
      // match `tableRowsQueryOptions` keys to saved column prefs without a layout shift.
      // Console variables gate self-hosted usage stats; resolve them here so the
      // org projects list can reserve chart space on first paint.
      await Promise.all([
        ensureConsoleAccountQueryData(queryClient),
        queryClient
          .ensureQueryData(consoleVariablesQueryOptions())
          .catch(() => {}),
      ])
    }
    return {
      currentUser: null,
    }
  },
})
