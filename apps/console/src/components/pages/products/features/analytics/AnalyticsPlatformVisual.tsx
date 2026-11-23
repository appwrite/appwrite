import { Ban, Bot, Filter, FilterX, Globe, Shield } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const AGENTS = [
  { name: 'GPTBot', value: '1.9K', percent: 100, selected: false },
  { name: 'AggressiveScraper', value: '1.2K', percent: 63, selected: true },
  { name: 'Googlebot', value: '840', percent: 44, selected: false },
] as const

const MENU = [
  { label: 'Filter by this value', icon: Filter, highlight: false },
  { label: 'Exclude this value', icon: FilterX, highlight: false },
  { label: 'Create firewall rule', icon: Shield, highlight: true },
] as const

/**
 * From a bot in the Analytics card, through its right-click menu, to a
 * Firewall rule scoped to the Site that serves the property.
 */
export function AnalyticsPlatformVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-2">
      <div className="relative w-full sm:w-[330px]">
        <ArtPanel delayMs={60} innerClassName="product-tone-shadow p-2">
          <p className="flex items-center gap-1.5 px-2 pb-1.5 pt-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            <Bot className="size-3" aria-hidden />
            {t('Top agents')}
          </p>
          <div className="space-y-0.5">
            {AGENTS.map((agent) => (
              <div
                key={agent.name}
                className={cn(
                  'relative flex h-7 items-center gap-2 overflow-hidden rounded-md px-2 text-[11.5px]',
                  agent.selected && 'ring-1 ring-[rgb(var(--tone-rgb)/0.5)]',
                )}
              >
                <span
                  className={cn(
                    'absolute inset-y-0 start-0 rounded-md',
                    agent.selected ? 'bg-[rgb(var(--tone-rgb)/0.2)]' : 'bg-[rgb(var(--tone-rgb)/0.08)]',
                  )}
                  style={{ width: `${agent.percent}%` }}
                  aria-hidden
                />
                <span dir="ltr" className="relative min-w-0 flex-1 truncate font-mono text-[11px] text-foreground">
                  {agent.name}
                </span>
                <span dir="ltr" className="relative font-medium tabular-nums text-foreground">
                  {agent.value}
                </span>
              </div>
            ))}
          </div>
        </ArtPanel>

        {/* Right-click menu opened on the selected agent. */}
        <div
          className="product-hero-rise absolute end-[-18%] top-[58%] z-[2] w-[200px] sm:end-[-42%]"
          style={riseStyle(380)}
        >
          <div className="rounded-lg border border-border bg-popover p-1 shadow-[0_16px_40px_-20px_rgb(0_0_0/0.45)]">
            {MENU.map((item) => {
              const Icon = item.icon
              return (
                <div
                  key={item.label}
                  className={cn(
                    'flex items-center gap-2 rounded-md px-2 py-1.5 text-[11.5px]',
                    item.highlight
                      ? 'bg-[rgb(var(--tone-rgb)/0.12)] font-medium text-foreground'
                      : 'text-muted-foreground',
                  )}
                >
                  <Icon className={cn('size-3.5', item.highlight && 'text-[var(--tone-ink)]')} aria-hidden />
                  {t(item.label)}
                </div>
              )
            })}
          </div>
        </div>
      </div>

      <div className="ms-12 h-16 border-s border-dashed border-foreground/25 sm:h-20" aria-hidden />

      <div className="flex items-center">
        <ArtPanel
          className="w-full sm:w-[300px]"
          delayMs={700}
          float
          floatDelayMs={300}
          innerClassName="flex items-start gap-2.5 border-[rgb(var(--tone-rgb)/0.45)] px-3 py-2.5 dark:border-[rgb(var(--tone-rgb)/0.45)]"
        >
          <ArtIconBadge icon={Ban} />
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <p className="text-[12px] font-semibold text-foreground">{t('Deny')}</p>
              <Badge variant="error" className="text-[10px]">
                <span dir="ltr">403</span>
              </Badge>
            </div>
            <p dir="ltr" className="mt-0.5 truncate font-mono text-[10.5px] text-muted-foreground">
              user_agent contains &quot;AggressiveScraper&quot;
            </p>
          </div>
        </ArtPanel>
        <ArtConnector className="hidden w-8 shrink-0 sm:block" travel travelDelayMs={1100} />
        <ArtPanel
          className="hidden shrink-0 sm:block"
          delayMs={850}
          innerClassName="flex items-center gap-2 px-3 py-2.5"
        >
          <ArtIconBadge icon={Globe} tone="secondary" />
          <span className="text-[11.5px] font-medium text-foreground">{t('Your Site')}</span>
        </ArtPanel>
      </div>

      <ArtChip className="end-0 top-0 hidden sm:block" delayMs={1000} floatDelayMs={600}>
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <Shield className="size-3 text-[var(--tone-ink)]" aria-hidden />
          {t('Same project, no keys to copy')}
        </span>
      </ArtChip>
    </div>
  )
}
