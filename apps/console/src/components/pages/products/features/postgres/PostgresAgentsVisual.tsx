import { Check, ScrollText } from 'lucide-react'
import { McpIcon } from '@/components/global/shared/McpIcon'
import {
  ArtChip,
  ArtIconBadge,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

type ToolCall = {
  tool: string
  argument: string
  status: string
}

/** Real tool names from the Appwrite MCP server's PostgreSQL catalog. */
const TOOL_CALLS: ToolCall[] = [
  {
    tool: 'postgresql_create',
    argument: 'agent-memory',
    status: 'Provisioned',
  },
  {
    tool: 'postgresql_create_extension',
    argument: 'pgvector',
    status: 'Installed',
  },
  { tool: 'postgresql_create_branch', argument: 'ttl: 1h', status: 'Ready' },
  {
    tool: 'postgresql_create_execution',
    argument: 'CREATE TABLE memories (…)',
    status: 'Done',
  },
]

export function PostgresAgentsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] pb-10 pt-2 text-start">
      <ArtWindow
        className="product-hero-rise relative z-[1]"
        style={riseStyle(60)}
        title={
          <span className="flex items-center gap-1.5">
            <McpIcon className="size-3" />
            {t('Appwrite MCP server')}
          </span>
        }
        bodyClassName="space-y-2 p-3"
      >
        {TOOL_CALLS.map((call, index) => (
          <div
            key={call.tool}
            className="product-hero-rise flex items-center gap-3 rounded-lg border border-border bg-muted/20 px-3 py-2"
            style={riseStyle(240 + index * 160)}
          >
            <div className="min-w-0 flex-1">
              <p
                dir="ltr"
                className="truncate font-mono text-[11px] font-medium text-foreground"
              >
                {call.tool}
              </p>
              <p
                dir="ltr"
                className="mt-0.5 truncate font-mono text-[10px] text-muted-foreground"
              >
                {call.argument}
              </p>
            </div>
            <span className="flex shrink-0 items-center gap-1 rounded-md bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
              <Check className="size-2.5" aria-hidden />
              {t(call.status)}
            </span>
          </div>
        ))}
      </ArtWindow>

      <ArtChip className="bottom-0 end-4" delayMs={980} floatDelayMs={700}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={ScrollText} tone="secondary" />
          <p className="text-[11px] font-medium text-foreground">
            {t('Reason saved to Activity')}
          </p>
        </div>
      </ArtChip>
    </div>
  )
}
