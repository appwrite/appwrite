import { createFileRoute, Outlet } from '@tanstack/react-router'
import { marketingPageLoader } from '@/lib/marketing/route-loader'

export const Route = createFileRoute('/_marketing')({
  staleTime: Number.POSITIVE_INFINITY,
  loaderDeps: () => ({}),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: MarketingLayoutRoute,
})

function MarketingLayoutRoute() {
  return <Outlet />
}
