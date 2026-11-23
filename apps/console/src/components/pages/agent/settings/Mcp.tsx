import { McpConnectionsPanel } from '@/components/global/providers/agent/McpConnections'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useT } from '@/lib/i18n/translate'

export function Mcp() {
  const t = useT()
  const { isAuthenticated } = useAuth()

  return (
    <div
      data-settings-card="MCP connections"
      className="w-full rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('MCP connections')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t('Servers available to the agent')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-0 py-0">
        {isAuthenticated ? (
          <McpConnectionsPanel />
        ) : (
          <div className="px-6 py-8">
            <EmptyState
              icon={McpIcon}
              iconSize="md"
              title="Sign in to manage MCP connections."
              description="Servers available to the agent"
              isEmpty
            />
          </div>
        )}
      </div>
    </div>
  )
}
