import { createFileRoute, Outlet } from '@tanstack/react-router'
import { NotFoundView } from '@/components/error/NotFound'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { DocsPageShell } from '@/lib/docs/DocsPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'

export const Route = createFileRoute('/docs')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  notFoundComponent: NotFoundView,
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: DocsLayoutRoute,
})

function DocsLayoutRoute() {
  return (
    <DocsPageShell>
      <Outlet />
    </DocsPageShell>
  )
}
