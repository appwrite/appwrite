import { Ban, Check, Plug, Sparkles, Wrench } from 'lucide-react'
import { ArtChip, ArtPanel, ArtToken, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { AnalyticsArtRow } from '@/components/pages/products/features/analytics/AnalyticsArtParts'
import { useT } from '@/lib/i18n/translate'

const TOOL_CALLS = [
  { tool: 'appwrite_search_tools', args: '"analytics metrics"' },
  { tool: 'appwrite_call_tool', args: 'analytics.listMetrics' },
] as const

const AGENTS = [
  { name: 'GPTBot', value: '1.9K', percent: 100, highlight: false },
  { name: 'AggressiveScraper', value: '1.2K', percent: 63, highlight: true },
  { name: 'ClaudeBot', value: '640', percent: 34, highlight: false },
] as const

/**
 * A conversation in any MCP client: the question, the Appwrite MCP tool calls
 * it triggers, the answer from Analytics, and a Firewall rule waiting for approval.
 */
export function AnalyticsMcpVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-6">
      <ArtChip className="-top-1 end-0 hidden sm:block" delayMs={900} floatDelayMs={400}>
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <Plug className="size-3 text-[var(--tone-ink)]" aria-hidden />
          {t('Appwrite MCP · OAuth, no API keys')}
        </span>
      </ArtChip>

      {/* The question. */}
      <div className="product-hero-rise flex justify-end" style={riseStyle(40)}>
        <p className="max-w-[78%] rounded-2xl rounded-ee-md bg-[rgb(var(--tone-rgb)/0.14)] px-3.5 py-2.5 text-[12.5px] leading-5 text-foreground">
          {t('Which bots hit /pricing this week? Anything worth blocking?')}
        </p>
      </div>

      {/* MCP tool calls. */}
      <div className="mt-4 space-y-1.5 ps-1">
        {TOOL_CALLS.map((call, index) => (
          <div
            key={call.tool}
            className="product-hero-rise flex items-center gap-2 text-[11px] text-muted-foreground"
            style={riseStyle(260 + index * 160)}
          >
            <Wrench className="size-3 shrink-0" aria-hidden />
            <code dir="ltr" className="min-w-0 truncate font-mono">
              <ArtToken tone="function">{call.tool}</ArtToken>
              <ArtToken tone="punctuation">(</ArtToken>
              <ArtToken tone="string">{call.args}</ArtToken>
              <ArtToken tone="punctuation">)</ArtToken>
            </code>
            <Check className="size-3 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
          </div>
        ))}
      </div>

      {/* The answer, grounded in the property's data. */}
      <ArtPanel className="mt-4 sm:me-8" delayMs={620} innerClassName="product-tone-shadow p-3.5">
        <p className="flex items-start gap-2 text-[12.5px] leading-5 text-foreground">
          <Sparkles className="mt-0.5 size-3.5 shrink-0 text-[var(--tone-ink)]" aria-hidden />
          <span>
            {t('3,740 bot visits on /pricing, 31% of its traffic. AggressiveScraper is up 340% on last week.')}
          </span>
        </p>
        <div className="mt-3 space-y-0.5">
          {AGENTS.map((agent) => (
            <AnalyticsArtRow
              key={agent.name}
              label={agent.name}
              value={agent.value}
              percent={agent.percent}
              mono
              highlight={agent.highlight}
            />
          ))}
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border/70 pt-3">
          <span className="text-[11.5px] text-muted-foreground">{t('Block it with a Firewall rule?')}</span>
          <span className="ms-auto inline-flex items-center gap-1.5 rounded-md bg-foreground px-2.5 py-1 text-[11px] font-medium text-background">
            <Ban className="size-3" aria-hidden />
            {t('Approve')}
          </span>
          <span className="inline-flex items-center rounded-md border border-border px-2.5 py-1 text-[11px] text-muted-foreground">
            {t('Not now')}
          </span>
        </div>
      </ArtPanel>
    </div>
  )
}
