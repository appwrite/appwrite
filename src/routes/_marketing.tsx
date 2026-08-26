import { createFileRoute, Outlet } from '@tanstack/react-router'
import {
  isInitSurfaceEnabled,
  isInitTicketSharePath,
} from '@/lib/init/init-surface'
import {
  marketingPageLoader,
  prefetchOptionalAuthHeaderData,
} from '@/lib/marketing/route-loader'

export const Route = createFileRoute('/_marketing')({
  staleTime: Number.POSITIVE_INFINITY,
  loaderDeps: () => ({}),
  loader: async ({ context, location }) => {
    // Ticket share pages stay up during pre-launch even when marketing routes
    // are otherwise locked.
    if (
      isInitTicketSharePath(location.pathname) &&
      isInitSurfaceEnabled()
    ) {
      await prefetchOptionalAuthHeaderData(context.queryClient)
      return
    }

    await marketingPageLoader(context.queryClient)
  },
  component: MarketingLayoutRoute,
})

function MarketingLayoutRoute() {
  return <Outlet />
}
