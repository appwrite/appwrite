import { useMemo, useState } from 'react'
import {
  BarChart3,
  Brain,
  ChevronLeft,
  Cpu,
  ExternalLink,
  PanelLeft,
  PanelLeftClose,
} from 'lucide-react'
import { useParams } from '@tanstack/react-router'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { Models } from '@/components/pages/agent/settings/Models'
import { Memory } from '@/components/pages/agent/settings/Memory'
import { Mcp } from '@/components/pages/agent/settings/Mcp'
import { Usage } from '@/components/pages/agent/settings/Usage'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  agentSettingsPath,
  type AgentSettingsSectionId,
} from '@/lib/assistant/agent-paths'
import { AGENT_SETTINGS_CARD_INDEX } from '@/lib/settings-search/agent-settings-cards'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type { AgentSettingsSectionId }
export { agentSettingsPath }

type AgentSettingsContentProps = {
  section: AgentSettingsSectionId
  onSectionChange: (section: AgentSettingsSectionId) => void
  onBack: () => void
  /** Right-pane only: open the matching org agent settings route in a new tab. */
  onOpenInNewTab?: () => void
  /** Page variant: toggle the conversations sidebar. */
  onToggleSidebar?: () => void
  /** Page variant: whether the conversations sidebar is open. */
  sidebarOpen?: boolean
  /** Optional padding classes for the settings toolbar (match console header). */
  toolbarClassName?: string
}

function SettingsSection({ section }: { section: AgentSettingsSectionId }) {
  if (section === 'mcp') return <Mcp />
  if (section === 'memory') return <Memory />
  if (section === 'usage') return <Usage />
  return <Models />
}

/** Settings body shared by the org agent page and the right-pane agent surface. */
export function AgentSettingsContent({
  section,
  onSectionChange,
  onBack,
  onOpenInNewTab,
  onToggleSidebar,
  sidebarOpen,
  toolbarClassName = 'px-3',
}: AgentSettingsContentProps) {
  const t = useT()
  const { orgId } = useParams({ strict: false })
  const [settingsNavSearch, setSettingsNavSearch] = useState('')

  const navItems = useMemo(() => {
    const settingsTo = (id: AgentSettingsSectionId) =>
      orgId ? agentSettingsPath(orgId, id) : '#'
    return [
      {
        id: 'models',
        label: t('Models'),
        to: settingsTo('models'),
        icon: Cpu,
        keywords: ['model', 'llm', 'openai', 'anthropic', 'provider', 'api key'],
      },
      {
        id: 'memory',
        label: t('Memory'),
        to: settingsTo('memory'),
        icon: Brain,
        keywords: [
          'memory',
          'memories',
          'preference',
          'instruction',
          'fact',
          'remember',
        ],
      },
      {
        id: 'mcp',
        label: t('MCP'),
        to: settingsTo('mcp'),
        icon: McpIcon,
        keywords: ['mcp', 'server', 'oauth', 'tools', 'connect'],
      },
      {
        id: 'usage',
        label: t('Usage'),
        to: settingsTo('usage'),
        icon: BarChart3,
        keywords: [
          'usage',
          'runs',
          'messages',
          'conversations',
          'tokens',
          'tool calls',
          'automations',
          'metrics',
        ],
      },
    ]
  }, [orgId, t])

  return (
    <div className="flex h-full min-h-0 w-full min-w-0 flex-col overflow-hidden bg-background">
      <div
        className={cn(
          'flex h-14 min-h-14 shrink-0 items-center justify-between border-b border-border',
          toolbarClassName,
        )}
      >        <div className="flex min-w-0 items-center gap-1">
          {onToggleSidebar ? (
            <button
              type="button"
              onClick={onToggleSidebar}
              className="flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              aria-label={sidebarOpen ? t('Close sidebar') : t('Open sidebar')}
              title={sidebarOpen ? t('Close sidebar') : t('Open sidebar')}
              {...analyticsAttrs(
                sidebarOpen ? 'agent-sidebar-close' : 'agent-sidebar-open',
              )}
            >
              {sidebarOpen ? (
                <PanelLeftClose className="h-3.5 w-3.5" />
              ) : (
                <PanelLeft className="h-3.5 w-3.5" />
              )}
            </button>
          ) : null}
          <button
            type="button"
            onClick={onBack}
            className="flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label={t('Back')}
            title={t('Back')}
            {...analyticsAttrs('agent-back')}
          >
            <ChevronLeft className="h-3.5 w-3.5" />
          </button>
          <p className="truncate px-1.5 text-[13px] font-semibold text-foreground">
            {t('Settings')}
          </p>
        </div>
        {onOpenInNewTab ? (
          <button
            type="button"
            onClick={onOpenInNewTab}
            className="flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label={t('Open in new tab')}
            title={t('Open in new tab')}
            {...analyticsAttrs('agent-open-new-tab')}
          >
            <ExternalLink className="h-3.5 w-3.5" />
          </button>
        ) : null}
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="mx-auto w-full min-w-0 max-w-7xl px-4 py-4 sm:px-6">
          <SettingsLayoutShell
            navItems={navItems}
            activeSectionId={section}
            cardIndex={AGENT_SETTINGS_CARD_INDEX}
            searchQuery={settingsNavSearch}
            onSearchQueryChange={setSettingsNavSearch}
            useRouteLinks={false}
            onNavigateToSection={(sectionId) => {
              if (
                sectionId === 'models' ||
                sectionId === 'memory' ||
                sectionId === 'mcp' ||
                sectionId === 'usage'
              ) {
                onSectionChange(sectionId)
              }
            }}
          >
            <SettingsSection section={section} />
          </SettingsLayoutShell>
        </div>
      </div>
    </div>
  )
}
