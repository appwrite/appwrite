import { useMemo, useState } from 'react'
import { ChevronLeft, Cpu, ExternalLink } from 'lucide-react'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { Models } from '@/components/pages/agent/settings/Models'
import { Mcp } from '@/components/pages/agent/settings/Mcp'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { AGENT_SETTINGS_CARD_INDEX } from '@/lib/settings-search/agent-settings-cards'
import { useT } from '@/lib/i18n/translate'

export type AgentSettingsSectionId = 'models' | 'mcp'

type AgentSettingsContentProps = {
  section: AgentSettingsSectionId
  onSectionChange: (section: AgentSettingsSectionId) => void
  onBack: () => void
  /** Right-pane only: open the matching `/agent/settings/...` route in a new tab. */
  onOpenInNewTab?: () => void
}

/** Settings body shared by the /agent page and the right-pane agent surface. */
export function AgentSettingsContent({
  section,
  onSectionChange,
  onBack,
  onOpenInNewTab,
}: AgentSettingsContentProps) {
  const t = useT()
  const [settingsNavSearch, setSettingsNavSearch] = useState('')

  const navItems = useMemo(
    () => [
      {
        id: 'models',
        label: t('Models'),
        to: '/agent/settings/models',
        icon: Cpu,
        keywords: ['model', 'llm', 'openai', 'anthropic', 'provider', 'api key'],
      },
      {
        id: 'mcp',
        label: t('MCP'),
        to: '/agent/settings/mcp',
        icon: McpIcon,
        keywords: ['mcp', 'server', 'oauth', 'tools', 'connect'],
      },
    ],
    [t],
  )

  return (
    <div className="flex h-full min-h-0 w-full min-w-0 flex-col overflow-hidden bg-background">
      <div className="flex h-14 min-h-14 shrink-0 items-center justify-between border-b border-border px-3">
        <div className="flex min-w-0 items-center gap-1">
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
              if (sectionId === 'models' || sectionId === 'mcp') {
                onSectionChange(sectionId)
              }
            }}
          >
            {section === 'mcp' ? <Mcp /> : <Models />}
          </SettingsLayoutShell>
        </div>
      </div>
    </div>
  )
}
