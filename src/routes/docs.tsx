import { createFileRoute, Outlet } from '@tanstack/react-router'
import { DocsPageShell } from '@/lib/docs/DocsPageShell'
import { prefetchOptionalAuthHeaderData } from '@/lib/marketing/route-loader'

export const Route = createFileRoute('/docs')({
  ssr: true,
  loader: async ({ context }) => {
    await prefetchOptionalAuthHeaderData(context.queryClient)
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
