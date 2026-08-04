import { createFileRoute, redirect } from '@tanstack/react-router'
import { AgentsView } from '@/components/pages/agent/AgentsView'
import {
  ensureConsoleAccountQueryData,
} from '@/lib/react-query/hooks'
import {
  parseAIChatActiveConversationId,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

export const Route = createFileRoute('/_public/agent/')({
  component: AgentIndexPage,
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    // Jump straight to the active agent so we don't paint /agent then replace.
    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account) return

    const activeConversationId = parseAIChatActiveConversationId(
      account.prefs as UserPrefs | undefined,
    )
    if (!activeConversationId) return

    throw redirect({
      to: '/agent/$agentId',
      params: { agentId: activeConversationId },
      replace: true,
    })
  },
})

function AgentIndexPage() {
  return <AgentsView />
}
