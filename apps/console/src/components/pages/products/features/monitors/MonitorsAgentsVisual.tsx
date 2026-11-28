import { Bot, Check, Plug, Wrench } from 'lucide-react'
import {
  ArtChip,
  ArtPanel,
  ArtToken,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  MonitorsSeverityPill,
  MonitorsStatusPill,
} from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'

const TOOL_CALLS = [
  { tool: 'appwrite_search_tools', args: '"firing alerts"' },
  { tool: 'appwrite_call_tool', args: 'alerts_list' },
] as const

/**
 * A conversation in any MCP client: the question, the Appwrite MCP tool calls
 * it triggers, the firing alert it finds, and an acknowledgement waiting for approval.
 */
export function MonitorsAgentsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-6">
      <ArtChip
        className="-top-1 end-0 hidden sm:block"
        delayMs={900}
        floatDelayMs={400}
      >
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <Plug className="size-3 text-[var(--tone-ink)]" aria-hidden />
          {t('Appwrite MCP · OAuth, no API keys')}
        </span>
      </ArtChip>

      <div
        className="product-hero-rise flex justify-end pt-6"
        style={riseStyle(40)}
      >
        <p className="max-w-[78%] rounded-2xl rounded-ee-md bg-[rgb(var(--tone-rgb)/0.14)] px-3.5 py-2.5 text-[12.5px] leading-5 text-foreground">
          {t(
            'Is anything firing on storefront-prod? If it is bandwidth, acknowledge it for me.',
          )}
        </p>
      </div>

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
            <Check
              className="size-3 shrink-0 text-emerald-600 dark:text-emerald-400"
              aria-hidden
            />
          </div>
        ))}
      </div>

      <ArtPanel
        className="mt-4 sm:me-8"
        delayMs={620}
        innerClassName="product-tone-shadow p-3.5"
      >
        <p className="flex items-start gap-2 text-[12.5px] leading-5 text-foreground">
          <Bot
            className="mt-0.5 size-3.5 shrink-0 text-[var(--tone-ink)]"
            aria-hidden
          />
          <span>
            {t(
              'One alert is firing: outbound bandwidth reached 6.2 GB in the last hour, 1.2× its 5 GB threshold, for 18 minutes.',
            )}
          </span>
        </p>
        <div className="mt-3 flex items-center gap-2 rounded-lg border border-border px-3 py-2">
          <span className="min-w-0 flex-1">
            <span className="block text-[11.5px] font-semibold text-foreground">
              {t('Egress spike')}
            </span>
            <span
              dir="ltr"
              className="block truncate font-mono text-[10px] text-muted-foreground"
            >
              storefront-prod · 6.2 GB &gt; 5 GB
            </span>
          </span>
          <MonitorsSeverityPill severity="critical" />
          <MonitorsStatusPill status="firing" />
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border/70 pt-3">
          <code
            dir="ltr"
            className="font-mono text-[10.5px] text-muted-foreground"
          >
            <ArtToken tone="function">alerts_update</ArtToken>
            <ArtToken tone="punctuation">(</ArtToken>
            <ArtToken tone="property">acknowledged</ArtToken>
            <ArtToken tone="punctuation">: </ArtToken>
            <ArtToken tone="keyword">true</ArtToken>
            <ArtToken tone="punctuation">)</ArtToken>
          </code>
          <span className="ms-auto inline-flex items-center gap-1.5 rounded-md bg-foreground px-2.5 py-1 text-[11px] font-medium text-background">
            <Check className="size-3" aria-hidden />
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
