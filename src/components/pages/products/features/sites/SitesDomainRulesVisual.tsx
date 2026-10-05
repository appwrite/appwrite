import { AppWindow, ArrowRight, GitBranch, Globe, Sparkles } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtChip, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type DomainRule = {
  id: string
  host: string
  rule?: string
  ruleTarget?: string
  icon: LucideIcon
  badge: 'success' | 'info' | 'inactive'
  label: string
  className: string
  primary?: boolean
}

/** Chip positions pair with the spoke endpoints in `SPOKES` (LTR coordinates, mirrored in RTL). */
const DOMAIN_RULES: DomainRule[] = [
  {
    id: 'production',
    host: 'acme.io',
    rule: 'Active deployment',
    icon: Globe,
    badge: 'success',
    label: 'Production',
    className: 'start-0 top-[2%]',
    primary: true,
  },
  {
    id: 'generated',
    host: '64d4d22d.appwrite.network',
    rule: 'Generated · active deployment',
    icon: Sparkles,
    badge: 'success',
    label: 'Live',
    className: 'end-0 top-[14%]',
  },
  {
    id: 'staging',
    host: 'staging.acme.io',
    rule: 'Git branch · staging',
    icon: GitBranch,
    badge: 'info',
    label: 'Staging',
    className: 'bottom-[14%] start-0',
  },
  {
    id: 'redirect',
    host: 'www.acme.io',
    ruleTarget: 'acme.io',
    icon: ArrowRight,
    badge: 'inactive',
    label: 'Redirect',
    className: 'bottom-[2%] end-0',
  },
]

const SPOKES = [
  { x: 20, y: 10 },
  { x: 80, y: 22 },
  { x: 20, y: 78 },
  { x: 80, y: 90 },
] as const

export function SitesDomainRulesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[420px] w-full max-w-[540px]">
      <svg
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
        className="product-hero-rise absolute inset-0 size-full rtl:-scale-x-100"
        style={riseStyle(300)}
        fill="none"
        aria-hidden
      >
        {SPOKES.map((spoke, index) => (
          <line
            key={index}
            x1="50"
            y1="50"
            x2={spoke.x}
            y2={spoke.y}
            className={index === 0 ? 'stroke-[var(--tone-ink)]' : 'stroke-foreground/20'}
            strokeWidth="1"
            strokeDasharray="4 4"
            vectorEffect="non-scaling-stroke"
          />
        ))}
      </svg>

      <div
        className="product-hero-rise absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2"
        style={riseStyle(60)}
      >
        <div className="relative flex size-[150px] items-center justify-center sm:size-[170px]">
          <div className="absolute inset-0 rounded-full border border-dashed border-foreground/15" aria-hidden />
          <div
            className="product-hero-orbit absolute inset-[14%] rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.4)]"
            aria-hidden
          />
          <div
            className="absolute inset-[24%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.25),transparent_70%)]"
            aria-hidden
          />
          <div className="product-tone-shadow relative flex flex-col items-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background px-3 py-2.5 dark:bg-card">
            <AppWindow className="size-5 text-[var(--tone-ink)]" strokeWidth={1.5} aria-hidden />
            <p className="mt-1.5 whitespace-nowrap text-[11px] font-semibold text-foreground">{t('Marketing site')}</p>
            <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">67abc12f</p>
          </div>
        </div>
      </div>

      {DOMAIN_RULES.map((entry, index) => {
        const Icon = entry.icon
        return (
          <ArtChip
            key={entry.id}
            className={cn('w-[47%] sm:w-[220px]', entry.className)}
            delayMs={450 + index * 150}
            floatDelayMs={index * 550}
          >
            <div className="flex items-center justify-between gap-2">
              <p
                dir="ltr"
                className={cn(
                  'min-w-0 truncate text-start font-mono text-[11px] font-medium',
                  entry.primary ? 'text-[var(--tone-ink)]' : 'text-foreground',
                )}
              >
                {entry.host}
              </p>
              <Badge variant={entry.badge} className="hidden shrink-0 text-[10px] sm:inline-flex">
                {t(entry.label)}
              </Badge>
            </div>
            <p className="mt-1 flex min-w-0 items-center gap-1.5 text-[10px] text-muted-foreground">
              <Icon className="size-3 shrink-0 rtl:-scale-x-100" aria-hidden />
              <span className="truncate">
                {entry.rule ? t(entry.rule) : null}
                {entry.ruleTarget ? (
                  <span dir="ltr" className="font-mono">{entry.ruleTarget}</span>
                ) : null}
              </span>
            </p>
          </ArtChip>
        )
      })}
    </div>
  )
}
