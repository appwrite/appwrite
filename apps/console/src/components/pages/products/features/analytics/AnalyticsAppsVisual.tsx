import { Bot, Download, Globe, MessageSquare, Smartphone, type LucideIcon } from 'lucide-react'
import { ArtChip, ArtIconBadge, ArtPanel } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TYPES: { label: string; icon: LucideIcon; active: boolean }[] = [
  { label: 'Website', icon: Globe, active: false },
  { label: 'Mobile app', icon: Smartphone, active: true },
  { label: 'Agent', icon: Bot, active: false },
]

const INSTALLS = [
  { label: 'Installs', value: '2.4K', change: '+14%' },
  { label: 'Updates', value: '860', change: '+6%' },
] as const

const FUNNEL = [
  { label: 'Received', value: '12.4K', percent: 100 },
  { label: 'Opened', value: '2.9K', percent: 23 },
] as const

/**
 * A mobile property: the type picks the panels, installs and updates on one
 * card, and the push funnel with the Messaging message behind the opens.
 */
export function AnalyticsAppsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] pb-10 pt-2">
      <ArtPanel className="mx-auto w-fit" delayMs={40} innerClassName="flex gap-1 p-1">
        {TYPES.map((type) => {
          const Icon = type.icon
          return (
            <span
              key={type.label}
              className={cn(
                'flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[11px]',
                type.active
                  ? 'bg-[rgb(var(--tone-rgb)/0.14)] font-medium text-foreground'
                  : 'text-muted-foreground',
              )}
            >
              <Icon className={cn('size-3.5', type.active && 'text-[var(--tone-ink)]')} aria-hidden />
              {t(type.label)}
            </span>
          )
        })}
      </ArtPanel>

      <div className="mt-4 grid gap-3 sm:grid-cols-[150px_minmax(0,1fr)]">
        <ArtPanel delayMs={260} float floatDelayMs={0} innerClassName="space-y-3 px-3.5 py-3">
          <p className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            <Download className="size-3" aria-hidden />
            {t('Installs')}
          </p>
          {INSTALLS.map((item) => (
            <div key={item.label}>
              <p className="text-[10px] text-muted-foreground">{t(item.label)}</p>
              <p className="mt-0.5 flex items-baseline gap-1.5">
                <span dir="ltr" className="font-aeonik-pro text-[18px] leading-none tracking-tight text-foreground">
                  {item.value}
                </span>
                <span dir="ltr" className="text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                  {item.change}
                </span>
              </p>
            </div>
          ))}
        </ArtPanel>

        <ArtPanel
          delayMs={420}
          float
          floatDelayMs={500}
          innerClassName="product-tone-shadow px-3.5 py-3"
        >
          <div className="flex items-center justify-between">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Push notifications')}
            </p>
            <span className="text-[10px] text-muted-foreground">
              <span dir="ltr" className="font-medium text-foreground">23%</span> {t('open rate')}
            </span>
          </div>
          <div className="mt-2.5 space-y-1.5">
            {FUNNEL.map((step, index) => (
              <div key={step.label} className="flex items-center gap-2 text-[11px]">
                <span className="w-14 shrink-0 text-muted-foreground">{t(step.label)}</span>
                <span className="relative h-5 flex-1 overflow-hidden rounded-md bg-muted/40">
                  <span
                    className={cn(
                      'product-hero-fill absolute inset-y-0 start-0 rounded-md',
                      index === 0 ? 'bg-[rgb(var(--tone-rgb)/0.22)]' : 'bg-[var(--tone-ink)]/70',
                    )}
                    style={{ width: `${step.percent}%` }}
                    aria-hidden
                  />
                </span>
                <span dir="ltr" className="w-10 shrink-0 text-end font-medium tabular-nums text-foreground">
                  {step.value}
                </span>
              </div>
            ))}
          </div>
          <div className="mt-3 flex items-center gap-2 border-t border-border/70 pt-2.5">
            <ArtIconBadge icon={MessageSquare} tone="secondary" className="size-6" />
            <div className="min-w-0 flex-1">
              <p className="truncate text-[11px] font-medium text-foreground">{t('Your order is on its way')}</p>
              <p className="truncate text-[10px] text-muted-foreground">
                {t('Messaging')} · <span dir="ltr">8.1K</span> {t('delivered')}
              </p>
            </div>
            <span dir="ltr" className="shrink-0 text-[11px] font-medium tabular-nums text-foreground">
              31%
            </span>
          </div>
        </ArtPanel>
      </div>

      <ArtChip className="bottom-0 start-[6%]" delayMs={900} floatDelayMs={300}>
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <Bot className="size-3 text-[var(--tone-ink)]" aria-hidden />
          {t('Agents: models and tools they call')}
        </span>
      </ArtChip>
    </div>
  )
}
