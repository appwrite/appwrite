import { createFileRoute } from '@tanstack/react-router'
import { redirectLegacyAgentLocation } from '@/lib/assistant/agent-paths'

export const Route = createFileRoute('/_public/agent/settings/mcp')({
  beforeLoad: async ({ context, location }) => {
    await redirectLegacyAgentLocation({
      queryClient: context.queryClient,
      pathname: location.pathname,
    })
  },
})
