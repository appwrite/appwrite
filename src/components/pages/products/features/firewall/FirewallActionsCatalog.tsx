import {
  Ban,
  ShieldAlert,
  ShieldOff,
  Timer,
  ArrowRightLeft,
  type LucideIcon,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type FirewallActionTile = {
  id: string
  title: string
  description: string
  outcome: string
  icon: LucideIcon
  badgeVariant: 'error' | 'processing' | 'warning' | 'info'
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

function ActionIcon({ icon: Icon }: { icon: LucideIcon }) {
  return (
    <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
      <Icon className="size-3.5 text-muted-foreground" aria-hidden />
    </span>
  )
}

function ActionCard({ action }: { action: FirewallActionTile }) {
  const t = useT()
  return (
    <div className="rounded-xl border border-border bg-background/80 p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <ActionIcon icon={action.icon} />
          <div className="min-w-0">
            <h4 className="text-[14px] font-semibold text-foreground">{t(action.title)}</h4>
            <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">
              {t(action.description)}
            </p>
          </div>
        </div>
        <Badge
          variant={action.badgeVariant}
          className={cn('shrink-0 text-[10px]')}
        >
          {t(action.outcome)}
        </Badge>
      </div>
    </div>
  )
}

export function FirewallActionsCatalog() {
  const t = useT()
  return (
    <div className="space-y-4">
      <p className="text-center text-[13px] leading-5 text-muted-foreground sm:text-[14px]">
        {t('One action per matching rule. Evaluation stops at the first match.')}
      </p>
      <div className="flex flex-wrap justify-center gap-3">
        {FIREWALL_ACTION_TILES.map((action) => (
          <div
            key={action.id}
            className="w-full sm:w-[calc((100%-0.75rem)/2)] lg:w-[calc((100%-1.5rem)/3)]"
          >
            <ActionCard action={action} />
          </div>
        ))}
      </div>
    </div>
  )
}
