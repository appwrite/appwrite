import {
  Database,
  Globe,
  HardDrive,
  Mail,
  Network,
  Plug,
  Server,
  Webhook,
  type LucideIcon,
} from 'lucide-react'
import {
  ArtChip,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { MonitorsCaption } from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/**
 * The four suggested monitors, each with a threshold suggested from the last
 * 7 days: twice the observed peak, rounded up to 1, 2, 3, or 5 × 10ⁿ.
 */
const PRESETS: {
  icon: LucideIcon
  title: string
  observed: string
  threshold: string
  highlight?: boolean
}[] = [
  {
    icon: Network,
    title: 'Egress spike',
    observed: 'Peak hour 2.4 GB',
    threshold: '> 5 GB / 1 h',
    highlight: true,
  },
  {
    icon: Database,
    title: 'Read surge',
    observed: 'Peak hour 380K',
    threshold: '> 1M / 1 h',
  },
  {
    icon: HardDrive,
    title: 'Storage used',
    observed: 'Now 18 GB',
    threshold: '> 50 GB',
  },
  {
    icon: Webhook,
    title: 'Webhook failures',
    observed: 'Peak hour 3',
    threshold: '> 10 / 1 h',
  },
]

const CHECKS: { icon: LucideIcon; label: string }[] = [
  { icon: Globe, label: 'HTTP monitor' },
  { icon: Server, label: 'DNS monitor' },
  { icon: Plug, label: 'TCP monitor' },
  { icon: Mail, label: 'SMTP monitor' },
]

/** The Monitors empty state: suggestions from real usage, then endpoint checks. */
export function MonitorsSuggestionsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-6">
      <ArtPanel delayMs={40} innerClassName="product-tone-shadow p-4">
        <MonitorsCaption>
          {t('Suggested from your last 7 days of usage')}
        </MonitorsCaption>
        <div className="mt-3 grid gap-2 sm:grid-cols-2">
          {PRESETS.map((preset, index) => {
            const Icon = preset.icon
            return (
              <div
                key={preset.title}
                className={cn(
                  'product-hero-rise flex items-start gap-2.5 rounded-lg border bg-background px-3 py-2.5 dark:bg-card',
                  preset.highlight
                    ? 'border-[rgb(var(--tone-rgb)/0.55)] shadow-[0_10px_30px_-18px_rgb(var(--tone-rgb)/0.8)]'
                    : 'border-border',
                )}
                style={riseStyle(200 + index * 110)}
              >
                <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                  <Icon className="size-3.5" aria-hidden />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-[12px] font-medium text-foreground">
                    {t(preset.title)}
                  </span>
                  <span className="mt-0.5 block text-[10.5px] text-muted-foreground">
                    {t(preset.observed)}
                  </span>
                  <span
                    dir="ltr"
                    className="mt-1.5 inline-block rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-foreground"
                  >
                    {preset.threshold}
                  </span>
                </span>
              </div>
            )
          })}
        </div>

        <MonitorsCaption className="mt-5">
          {t('Check that your endpoints respond')}
        </MonitorsCaption>
        <div className="mt-2.5 grid grid-cols-2 gap-2 sm:grid-cols-4">
          {CHECKS.map((check, index) => {
            const Icon = check.icon
            return (
              <span
                key={check.label}
                className="product-hero-rise flex items-center gap-1.5 rounded-lg border border-border bg-background px-2.5 py-2 text-[11px] text-foreground dark:bg-card"
                style={riseStyle(700 + index * 80)}
              >
                <Icon className="size-3.5 text-muted-foreground" aria-hidden />
                {t(check.label)}
              </span>
            )
          })}
        </div>
      </ArtPanel>

      <ArtChip
        className="-top-1 end-0 hidden sm:block"
        delayMs={1000}
        floatDelayMs={300}
      >
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <span
            className="size-1.5 rounded-full bg-[var(--tone-ink)]"
            aria-hidden
          />
          {t('Twice your peak, rounded up')}
        </span>
      </ArtChip>
    </div>
  )
}
