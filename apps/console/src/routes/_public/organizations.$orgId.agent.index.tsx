import { createFileRoute, redirect } from '@tanstack/react-router'
import { AgentsView } from '@/components/pages/agent/AgentsView'
import { ensureConsoleAccountQueryData } from '@/lib/react-query/hooks'
import {
  parseAIChatActiveConversationId,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

export const Route = createFileRoute('/_public/organizations/$orgId/agent/')({
  component: AgentIndexPage,
  loader: async ({ context, params }) => {
    if (typeof window === 'undefined') return

    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account) return

    const activeConversationId = parseAIChatActiveConversationId(
      account.prefs as UserPrefs | undefined,
    )
    if (!activeConversationId) return

    throw redirect({
      to: '/organizations/$orgId/agent/$agentId',
      params: { orgId: params.orgId, agentId: activeConversationId },
      replace: true,
    })
  },
})

function AgentIndexPage() {
  return <AgentsView />
}
