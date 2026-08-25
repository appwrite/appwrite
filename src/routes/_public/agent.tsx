import { createFileRoute, Outlet } from '@tanstack/react-router'
import { redirectLegacyAgentLocation } from '@/lib/assistant/agent-paths'

/** Legacy `/agent` tree: redirect into `/organizations/$orgId/agent`. */
export const Route = createFileRoute('/_public/agent')({
  ssr: false,
  beforeLoad: async ({ context, location }) => {
    await redirectLegacyAgentLocation({
      queryClient: context.queryClient,
      pathname: location.pathname,
    })
  },
  component: () => <Outlet />,
})
