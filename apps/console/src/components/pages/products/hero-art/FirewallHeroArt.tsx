import { ArrowRightLeft, Ban, Globe, Server, Shield, ShieldOff, Timer, type LucideIcon } from 'lucide-react'
import type { CSSProperties } from 'react'
import { ArtChip, ArtLiveDot, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const LANES = ['top-[18%]', 'top-[39%]', 'top-[61%]', 'top-[82%]'] as const

const TRAFFIC_REQUESTS = [
  { id: 'req-1', outcome: 'pass', tone: 'bg-emerald-500', delayMs: 0, lane: 0 },
  { id: 'req-2', outcome: 'block', tone: 'bg-red-500', delayMs: 450, lane: 1 },
  { id: 'req-3', outcome: 'pass', tone: 'bg-emerald-500', delayMs: 900, lane: 2 },
  { id: 'req-4', outcome: 'pass', tone: 'bg-violet-500', delayMs: 1350, lane: 3 },
  { id: 'req-5', outcome: 'block', tone: 'bg-amber-500', delayMs: 1800, lane: 0 },
  { id: 'req-6', outcome: 'pass', tone: 'bg-emerald-500', delayMs: 2250, lane: 1 },
  { id: 'req-7', outcome: 'block', tone: 'bg-red-500', delayMs: 2700, lane: 3 },
  { id: 'req-8', outcome: 'pass', tone: 'bg-emerald-500', delayMs: 3150, lane: 2 },
] as const

const RULES: {
  priority: number
  name: string
  condition: string
  action: string
  icon: LucideIcon
  toneClass: string
}[] = [
  {
    priority: 1,
    name: 'allow-office',
    condition: 'ip in 10.0.0.0/8',
    action: 'Bypass',
    icon: ShieldOff,
    toneClass: 'text-blue-600 dark:text-blue-400',
  },
  {
    priority: 2,
    name: 'block-scrapers',
    condition: 'user_agent contains "curl"',
    action: 'Deny',
    icon: Ban,
    toneClass: 'text-red-600 dark:text-red-400',
  },
  {
    priority: 3,
    name: 'api-rate-limit',
    condition: 'path starts_with "/v1"',
    action: 'Rate limit',
    icon: Timer,
    toneClass: 'text-amber-600 dark:text-amber-400',
  },
  {
    priority: 4,
    name: 'eu-redirect',
    condition: 'country == "FR"',
    action: 'Redirect',
    icon: ArrowRightLeft,
    toneClass: 'text-slate-600 dark:text-slate-300',
  },
]

function Endpoint({
  icon: Icon,
  label,
  className,
  delayMs,
}: {
  icon: LucideIcon
  label: string
  className: string
  delayMs: number
}) {
  const t = useT()
  return (
    <div className={cn('absolute top-1/2 z-[2] -translate-y-1/2', className)}>
      <ArtPanel
        delayMs={delayMs}
        innerClassName="flex flex-col items-center gap-1.5 px-2.5 py-2.5 sm:flex-row sm:gap-2 sm:px-3"
      >
        <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40 text-muted-foreground">
          <Icon className="size-3.5" aria-hidden />
        </span>
        <span className="text-[11px] font-medium text-foreground sm:text-[12px]">{t(label)}</span>
      </ArtPanel>
    </div>
  )
}

export function FirewallHeroArt() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[420px] max-w-5xl text-start sm:h-[440px]">
      <div
        className="absolute inset-x-0 top-1/2 h-[260px] -translate-y-1/2 [mask-image:linear-gradient(to_right,transparent,black_10%,black_90%,transparent)]"
        aria-hidden
      >
        {LANES.map((lane) => (
          <div key={lane} className={cn('absolute inset-x-0 h-0 border-t border-dashed border-foreground/20', lane)} />
        ))}
        {TRAFFIC_REQUESTS.map((request) => (
          <span
            key={request.id}
            className={cn(
              'absolute size-2 rounded-full opacity-0',
              LANES[request.lane],
              request.tone,
              request.outcome === 'block' ? 'product-firewall-hero-block' : 'product-firewall-hero-pass',
            )}
            style={{ '--fw-delay': `${request.delayMs}ms` } as CSSProperties}
          />
        ))}
      </div>

      <div
        className="pointer-events-none absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-[linear-gradient(to_bottom,transparent,rgb(var(--tone-rgb)/0.55),transparent)]"
        aria-hidden
      />
      <div
        className="pointer-events-none absolute left-1/2 top-1/2 h-[340px] w-[min(420px,70%)] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.16),transparent)] blur-2xl"
        aria-hidden
      />

      <Endpoint icon={Globe} label="Incoming" className="start-0 sm:start-[2%]" delayMs={120} />
      <Endpoint icon={Server} label="Your app" className="end-0 sm:end-[2%]" delayMs={260} />

      <div className="absolute left-1/2 top-1/2 z-[1] w-[min(340px,56%)] -translate-x-1/2 -translate-y-1/2">
        <div className="product-hero-rise mb-3 flex justify-center" style={riseStyle(80)}>
          <span className="inline-flex items-center gap-2 rounded-full border border-[rgb(var(--tone-rgb)/0.45)] bg-background/95 py-1 pe-3 ps-1 shadow-[0_0_24px_-6px_rgb(var(--tone-rgb)/0.6)] dark:bg-card">
            <span className="product-hero-firewall-shield-pulse flex size-6 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.16)]">
              <Shield className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
            </span>
            <span className="text-[12px] font-medium text-foreground">{t('Firewall')}</span>
            <ArtLiveDot />
          </span>
        </div>

        <ul className="space-y-2">
          {RULES.map((rule, index) => {
            const Icon = rule.icon
            return (
              <li key={rule.name}>
                <ArtPanel
                  delayMs={300 + index * 130}
                  innerClassName="flex items-center gap-2.5 px-2.5 py-2 sm:px-3"
                >
                  <span className="font-mono text-[10px] text-muted-foreground">{rule.priority}</span>
                  <span className="min-w-0 flex-1">
                    <span dir="ltr" className="block truncate font-mono text-[11.5px] text-foreground sm:text-[12px]">
                      {rule.name}
                    </span>
                    <span dir="ltr" className="mt-0.5 hidden truncate font-mono text-[10.5px] text-muted-foreground sm:block">
                      {rule.condition}
                    </span>
                  </span>
                  <span className="flex shrink-0 items-center gap-1 rounded-md border border-border px-1.5 py-0.5 text-[10.5px] text-foreground sm:gap-1.5 sm:px-2 sm:text-[11px]">
                    <Icon className={cn('size-3', rule.toneClass)} aria-hidden />
                    <span className="hidden sm:inline">{t(rule.action)}</span>
                  </span>
                </ArtPanel>
              </li>
            )
          })}
        </ul>
      </div>

      <ArtChip className="start-[4%] top-[2%] hidden sm:block" delayMs={700} floatDelayMs={0}>
        <div className="flex items-center gap-2">
          <ArtLiveDot />
          <span dir="ltr" className="font-aeonik-pro text-[15px] tracking-tight text-foreground">1.24M</span>
          <span className="text-[11px] text-muted-foreground">{t('Requests')}</span>
        </div>
      </ArtChip>
      <ArtChip className="bottom-[2%] start-[8%] sm:start-[12%]" delayMs={850} floatDelayMs={700}>
        <div className="flex items-center gap-2">
          <Ban className="size-3.5 text-red-600 dark:text-red-400" aria-hidden />
          <span dir="ltr" className="font-aeonik-pro text-[15px] tracking-tight text-foreground">18.4K</span>
          <span className="text-[11px] text-muted-foreground">{t('Blocked')}</span>
        </div>
      </ArtChip>
      <ArtChip className="bottom-[8%] end-[4%] hidden sm:block" delayMs={1000} floatDelayMs={1400}>
        <div className="flex items-center gap-2">
          <Timer className="size-3.5 text-amber-600 dark:text-amber-400" aria-hidden />
          <span dir="ltr" className="font-aeonik-pro text-[15px] tracking-tight text-foreground">2.1K</span>
          <span className="text-[11px] text-muted-foreground">{t('Rate limited')}</span>
        </div>
      </ArtChip>
    </div>
  )
}
