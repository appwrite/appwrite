import {
  Ban,
  ShieldAlert,
  ShieldOff,
  Timer,
  ArrowRightLeft,
  type LucideIcon,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type FirewallActionTile = {
  id: string
  title: string
  description: string
  outcome: string
  icon: LucideIcon
  badgeVariant: 'error' | 'processing' | 'warning' | 'info'
  /** Centers the two-tile second row on the six-column `sm` grid. */
  smColStart?: string
}

const FIREWALL_ACTION_TILES: FirewallActionTile[] = [
  {
    id: 'deny',
    title: 'Deny',
    description: 'Reject matching requests before they reach your project.',
    outcome: '403',
    icon: Ban,
    badgeVariant: 'error',
  },
  {
    id: 'bypass',
    title: 'Bypass',
    description: 'Allow the request and skip later Firewall rules.',
    outcome: 'Continue',
    icon: ShieldOff,
    badgeVariant: 'processing',
  },
  {
    id: 'rate-limit',
    title: 'Rate limit',
    description: 'Throttle matching requests that exceed a per-IP quota.',
    outcome: '429',
    icon: Timer,
    badgeVariant: 'warning',
  },
  {
    id: 'redirect',
    title: 'Redirect',
    description: 'Send matching clients to another location with a 3xx status.',
    outcome: '3xx',
    icon: ArrowRightLeft,
    badgeVariant: 'info',
    smColStart: 'sm:col-start-2 lg:col-start-auto',
  },
  {
    id: 'challenge',
    title: 'Challenge',
    description:
      'Present a challenge before allowing suspicious clients through.',
    outcome: 'Challenge',
    icon: ShieldAlert,
    badgeVariant: 'processing',
  },
]

export function FirewallActionsCatalog() {
  const t = useT()
  return (
    <div className="space-y-6">
      <div className="flex flex-col items-center">
        <div
          className="product-hero-rise inline-flex items-center gap-2 rounded-full border border-border bg-background px-3 py-1.5 shadow-sm dark:bg-card"
          style={riseStyle(0)}
        >
          <span className="size-1.5 rounded-full bg-[var(--tone-ink)] shadow-[0_0_8px_rgb(var(--tone-rgb))]" aria-hidden />
          <span className="text-[12px] text-muted-foreground sm:text-[13px]">
            {t('One action per matching rule. Evaluation stops at the first match.')}
          </span>
        </div>
        <div className="hidden h-6 w-0 border-s border-dashed border-foreground/25 lg:block" aria-hidden />
      </div>

      <div className="relative">
        <span
          className="absolute inset-x-[10%] -top-6 hidden border-t border-dashed border-foreground/25 lg:block"
          aria-hidden
        />
        <div className="grid gap-3 sm:grid-cols-6 lg:grid-cols-5">
          {FIREWALL_ACTION_TILES.map((action, index) => (
            <div key={action.id} className={cn('relative sm:col-span-2 lg:col-span-1', action.smColStart)}>
              <span
                className="absolute -top-6 start-1/2 hidden h-6 w-0 border-s border-dashed border-foreground/25 lg:block"
                aria-hidden
              />
              <ArtPanel
                className="h-full"
                innerClassName="flex h-full flex-col p-4"
                delayMs={120 + index * 100}
                float
                floatDelayMs={index * 400}
              >
                <div className="flex items-center justify-between gap-3">
                  <ArtIconBadge icon={action.icon} tone={index % 2 === 0 ? 'primary' : 'secondary'} className="size-8" />
                  <Badge variant={action.badgeVariant} className="shrink-0 text-[10px]">
                    {/^\d/.test(action.outcome) ? <span dir="ltr">{action.outcome}</span> : t(action.outcome)}
                  </Badge>
                </div>
                <h4 className="mt-3 text-[14px] font-semibold text-foreground">{t(action.title)}</h4>
                <p className="mt-1 text-[12.5px] leading-5 text-muted-foreground">{t(action.description)}</p>
              </ArtPanel>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
