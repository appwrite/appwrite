import type { CSSProperties } from 'react'
import {
  CheckCircle2,
  Shield,
  ShieldAlert,
  ShieldCheck,
  ShieldX,
  XCircle,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from './MockSyntax'

type FirewallAction = 'block' | 'allow' | 'challenge'

const FIREWALL_RULES = [
  {
    id: 'rule-1',
    name: 'Block suspicious IPs',
    condition: '192.168.1.100',
    action: 'block' as const,
    enabled: true,
    highlightOnHover: true,
  },
  {
    id: 'rule-2',
    name: 'Rate limit API endpoints',
    condition: '/api/*',
    action: 'challenge' as const,
    enabled: true,
  },
  {
    id: 'rule-3',
    name: 'Allow admin panel',
    condition: '/admin/*',
    action: 'allow' as const,
    enabled: true,
  },
] as const

const TRAFFIC_STATS = [
  {
    id: 'blocked',
    label: 'Blocked',
    value: '34.2k',
  },
  {
    id: 'allowed',
    label: 'Allowed',
    value: '198k',
  },
  {
    id: 'challenged',
    label: 'Challenged',
    value: '13k',
  },
] as const

const TRAFFIC_REQUESTS = [
  { id: 'req-1', outcome: 'pass', tone: 'allowed', delayMs: 0 },
  { id: 'req-2', outcome: 'block', tone: 'blocked', delayMs: 220 },
  { id: 'req-3', outcome: 'pass', tone: 'allowed', delayMs: 440 },
  { id: 'req-4', outcome: 'pass', tone: 'challenged', delayMs: 660 },
  { id: 'req-5', outcome: 'block', tone: 'blocked', delayMs: 880 },
] as const

const ACTION_CONFIG: Record<
  FirewallAction,
  { label: string; Icon: LucideIcon }
> = {
  block: { label: 'Block', Icon: ShieldX },
  allow: { label: 'Allow', Icon: ShieldCheck },
  challenge: { label: 'Challenge', Icon: ShieldAlert },
}

const ACTION_BADGE_HOVER_CLASS: Record<FirewallAction, string> = {
  block:
    'group-hover:bg-red-500/10 group-hover:text-red-700 dark:group-hover:text-red-400 motion-reduce:group-hover:bg-muted motion-reduce:group-hover:text-foreground/80',
  allow:
    'group-hover:bg-emerald-500/10 group-hover:text-emerald-700 dark:group-hover:text-emerald-400 motion-reduce:group-hover:bg-muted motion-reduce:group-hover:text-foreground/80',
  challenge:
    'group-hover:bg-amber-500/10 group-hover:text-amber-700 dark:group-hover:text-amber-400 motion-reduce:group-hover:bg-muted motion-reduce:group-hover:text-foreground/80',
}

const REQUEST_TONE_CLASS = {
  allowed: 'bg-muted-foreground group-hover:bg-emerald-500 motion-reduce:group-hover:bg-muted-foreground',
  challenged:
    'bg-muted-foreground group-hover:bg-amber-500 motion-reduce:group-hover:bg-muted-foreground',
  blocked: 'bg-muted-foreground group-hover:bg-red-500 motion-reduce:group-hover:bg-muted-foreground',
} as const

function ActionBadge({ action }: { action: FirewallAction }) {
  const t = useT()
  const { label, Icon } = ACTION_CONFIG[action]

  return (
    <Badge
      variant="inactive"
      className={cn(
        'h-5 shrink-0 px-1.5 text-[9px] transition-[color,background-color] duration-300 sm:text-[10px]',
        ACTION_BADGE_HOVER_CLASS[action],
      )}
    >
      <Icon className="size-2.5" aria-hidden />
      {t(label)}
    </Badge>
  )
}

function TrafficFlowStrip() {
  const t = useT()
  return (
    <div className="overflow-hidden rounded-md border border-border/70 bg-muted/8 px-2.5 py-2">
      <div className="relative h-5">
        <div className="absolute inset-x-1 top-1/2 h-px -translate-y-1/2 bg-border" aria-hidden />
        <span
          className={cn(
            'product-bento-firewall-shield-pulse absolute left-1/2 top-1/2 flex size-5 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-background',
          )}
        >
          <Shield className={cn('size-2.5', productBentoIdle.brandIcon)} aria-hidden />
        </span>
        {TRAFFIC_REQUESTS.map((request) => (
          <span
            key={request.id}
            className={cn(
              'absolute top-1/2 size-1.5 rounded-full opacity-0',
              REQUEST_TONE_CLASS[request.tone],
              request.outcome === 'block'
                ? 'product-bento-firewall-request-block'
                : 'product-bento-firewall-request-pass',
            )}
            style={{ '--fw-delay': `${request.delayMs}ms` } as CSSProperties}
            aria-hidden
          />
        ))}
      </div>
      <p
        className="mt-1.5 text-center text-[9px] text-muted-foreground opacity-0 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100 sm:text-[10px]"
        style={{ transitionDelay: '120ms' }}
      >
        {t('Requests evaluated against active rules')}
      </p>
    </div>
  )
}

function FirewallRuleRow({
  name,
  condition,
  action,
  enabled,
  index,
  highlightOnHover = false,
}: {
  name: string
  condition: string
  action: FirewallAction
  enabled: boolean
  index: number
  highlightOnHover?: boolean
}) {
  const t = useT()
  const ActionIcon = ACTION_CONFIG[action].Icon

  return (
    <div
      className={cn(
        'flex items-center gap-2 rounded-md border border-border/70 px-2 py-1.5 transition-[border-color,background-color,opacity,transform] duration-300',
        productBentoContainer.panelMd,
        enabled
          ? 'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_22%,var(--border))] group-hover:bg-background motion-reduce:group-hover:border-border/70 motion-reduce:group-hover:bg-card/70'
          : 'opacity-60',
        highlightOnHover &&
          'group-hover:animate-[product-bento-oauth-highlight_0.45s_ease-out_both] motion-reduce:group-hover:animate-none',
      )}
      style={{ animationDelay: highlightOnHover ? `${120 + index * 70}ms` : `${index * 50}ms` }}
    >
      <span className="flex shrink-0 items-center justify-center">
        {enabled ? (
          <CheckCircle2
            className="size-3.5 text-muted-foreground transition-colors duration-300 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 motion-reduce:group-hover:text-muted-foreground"
            aria-hidden
          />
        ) : (
          <XCircle className="size-3.5 text-muted-foreground" aria-hidden />
        )}
      </span>
      <ActionIcon
        className={cn(
          'size-3.5 shrink-0 text-muted-foreground transition-colors duration-300',
          action === 'block' &&
            'group-hover:text-red-600 dark:group-hover:text-red-400 motion-reduce:group-hover:text-muted-foreground',
          action === 'allow' &&
            'group-hover:text-emerald-600 dark:group-hover:text-emerald-400 motion-reduce:group-hover:text-muted-foreground',
          action === 'challenge' &&
            'group-hover:text-amber-600 dark:group-hover:text-amber-400 motion-reduce:group-hover:text-muted-foreground',
        )}
        aria-hidden
      />
      <div className="min-w-0 flex-1">
        <p className={cn('truncate text-[11px] font-medium sm:text-[12px]', productBentoIdle.text)}>
          {t(name)}
        </p>
        <p className="truncate font-mono text-[10px] text-muted-foreground sm:text-[11px]">
          {condition}
        </p>
      </div>
      <ActionBadge action={action} />
    </div>
  )
}

export function FirewallProductVisual() {
  const t = useT()
  return (
    <div className="absolute inset-0 flex flex-col">
      <div className={cn('mx-auto flex h-full min-h-0 w-full max-w-[21rem] flex-col', productBentoContainer.shell)}>
        <div className={cn(productBentoContainer.header, 'px-3.5 py-2.5')}>
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <p className={cn('text-[12px] font-medium sm:text-[13px]', productBentoIdle.text)}>
                {t('Traffic rules')}
              </p>
              <p className="mt-0.5 text-[11px] text-muted-foreground sm:text-[12px]">
                {t('Filter requests before they reach your APIs')}
              </p>
            </div>
            <span className="relative mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-background">
              <Shield
                className={cn(
                  'size-3.5 motion-reduce:animate-none group-hover:animate-pulse',
                  productBentoIdle.brandIcon,
                )}
                aria-hidden
              />
            </span>
          </div>
        </div>

        <div className="space-y-2 overflow-hidden p-3">
          <div className="grid grid-cols-3 gap-1.5">
              {TRAFFIC_STATS.map((stat) => (
                <div
                  key={stat.id}
                  className="rounded-md border border-border bg-background px-2 py-1.5 text-center shadow-sm transition-[border-color,box-shadow] duration-300 group-hover:border-border group-hover:shadow-md"
                >
                  <p className="text-[11px] font-semibold tabular-nums text-foreground sm:text-[12px]">
                    {stat.value}
                  </p>
                  <p className="text-[9px] font-medium text-muted-foreground sm:text-[10px]">
                    {t(stat.label)}
                  </p>
                </div>
              ))}
            </div>

            <TrafficFlowStrip />

          <div className="space-y-1.5">
            {FIREWALL_RULES.map((rule, index) => (
              <FirewallRuleRow
                key={rule.id}
                name={rule.name}
                condition={rule.condition}
                action={rule.action}
                enabled={rule.enabled}
                index={index}
                highlightOnHover={'highlightOnHover' in rule ? rule.highlightOnHover : false}
              />
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
