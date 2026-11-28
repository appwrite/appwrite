import {
  ArrowDownUp,
  BarChart3,
  Database,
  Folder,
  Image,
  MessageSquare,
  Radio,
  Server,
  Users,
  Wallet,
  Webhook,
  Zap,
  type LucideIcon,
} from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type MetricGroup = {
  title: string
  icon: LucideIcon
  metrics: string[]
  /** What one monitor of this group can watch. */
  scope: string
  cloud?: boolean
}

/** Every metric a usage monitor can watch, grouped as the Console's metric picker groups them. */
const GROUPS: MetricGroup[] = [
  {
    title: 'Requests and bandwidth',
    icon: ArrowDownUp,
    metrics: ['Requests', 'Inbound bandwidth', 'Outbound bandwidth'],
    scope: 'The whole project',
  },
  {
    title: 'Compute',
    icon: Zap,
    metrics: ['Executions', 'Execution GB-hours'],
    scope: 'The project, a function, or a site',
  },
  {
    title: 'Databases',
    icon: Database,
    metrics: ['Database reads', 'Database writes'],
    scope: 'The project or one database',
  },
  {
    title: 'Storage',
    icon: Folder,
    metrics: ['Storage used', 'File storage', 'Database storage'],
    scope: 'The project, a bucket, a database, a function, or a site',
  },
  {
    title: 'Auth',
    icon: Users,
    metrics: ['Monthly active users', 'Sign-ups', 'OTP attempts'],
    scope: 'The whole project',
  },
  {
    title: 'Realtime',
    icon: Radio,
    metrics: ['Concurrent connections'],
    scope: 'The whole project',
  },
  {
    title: 'Messaging',
    icon: MessageSquare,
    metrics: ['Messages sent', 'SMS messages sent', 'Messages failed'],
    scope: 'The whole project',
  },
  {
    title: 'Webhooks',
    icon: Webhook,
    metrics: ['Webhook events sent', 'Webhook events failed'],
    scope: 'The whole project',
  },
  {
    title: 'Avatars',
    icon: Image,
    metrics: ['Screenshots generated'],
    scope: 'The whole project',
  },
  {
    title: 'Dedicated databases',
    icon: Server,
    metrics: [
      'CPU',
      'Memory',
      'Disk usage',
      'Connections',
      'Queries per second',
      'Read and write IOPS',
    ],
    scope: 'One dedicated database',
    cloud: true,
  },
  {
    title: 'Analytics',
    icon: BarChart3,
    metrics: ['Analytics events', 'Analytics pageviews'],
    scope: 'The whole project, bot traffic excluded',
    cloud: true,
  },
  {
    title: 'Budget',
    icon: Wallet,
    metrics: ['Budget used'],
    scope: 'Your organization, from billing settings',
    cloud: true,
  },
]

const COMPARATORS = ['>', '≥', '<', '≤'] as const
const WINDOWS = [
  '5 min',
  '15 min',
  '1 hour',
  '6 hours',
  '1 day',
  '7 days',
  '30 days',
] as const

export function MonitorsMetricsCatalog() {
  const t = useT()

  return (
    <div className="space-y-12">
      <ul className="mx-auto grid max-w-6xl gap-x-8 gap-y-8 text-start sm:grid-cols-2 lg:grid-cols-4">
        {GROUPS.map((group, index) => {
          const Icon = group.icon
          return (
            <li
              key={group.title}
              className="product-hero-rise border-t border-border pt-4"
              style={riseStyle(120 + index * 50)}
            >
              <div className="flex items-center gap-2">
                <Icon
                  className={cn(
                    'size-4',
                    index % 2 === 0
                      ? 'text-[var(--tone-ink)]'
                      : 'text-foreground/70',
                  )}
                  strokeWidth={1.75}
                  aria-hidden
                />
                <h4 className="text-[14px] font-medium text-foreground">
                  {t(group.title)}
                </h4>
                {group.cloud ? (
                  <span className="ms-auto rounded-full border border-border px-1.5 text-[10px] leading-4 text-muted-foreground">
                    {t('Cloud')}
                  </span>
                ) : null}
              </div>
              <p className="mt-2 text-[13px] leading-5 text-foreground/85">
                {group.metrics.map((metric) => t(metric)).join(' · ')}
              </p>
              <p className="mt-1.5 text-[12px] leading-5 text-muted-foreground">
                {t(group.scope)}
              </p>
            </li>
          )
        })}
      </ul>

      <div className="mx-auto flex max-w-4xl flex-col items-center gap-4 rounded-2xl border border-border bg-background/70 px-5 py-4 text-start sm:flex-row sm:justify-between dark:bg-card/40">
        <div className="flex items-center gap-3">
          <span className="text-[12px] text-muted-foreground">
            {t('Alert when the value is')}
          </span>
          <span className="flex gap-1" dir="ltr">
            {COMPARATORS.map((comparator) => (
              <span
                key={comparator}
                className="flex size-7 items-center justify-center rounded-md border border-border bg-background font-mono text-[13px] text-foreground dark:bg-card"
              >
                {comparator}
              </span>
            ))}
          </span>
        </div>
        <div className="flex flex-wrap items-center justify-center gap-1.5">
          <span className="text-[12px] text-muted-foreground">
            {t('Counted over')}
          </span>
          {WINDOWS.map((window, index) => (
            <span
              key={window}
              className={cn(
                'rounded-full border px-2 py-0.5 text-[11px]',
                index === 2
                  ? 'border-[rgb(var(--tone-rgb)/0.5)] bg-[rgb(var(--tone-rgb)/0.12)] text-[var(--tone-ink)]'
                  : 'border-border text-muted-foreground',
              )}
            >
              {t(window)}
            </span>
          ))}
        </div>
      </div>
    </div>
  )
}
