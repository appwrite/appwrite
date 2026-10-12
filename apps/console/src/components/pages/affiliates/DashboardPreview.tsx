import { Gift } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const CLICKS_COLOR = 'var(--chart-2)'
const SIGNUPS_COLOR = 'var(--chart-brand)'
const CONVERSIONS_COLOR = 'var(--chart-1)'

const METRICS = [
  { label: 'Clicks', value: '2.4K', hint: 'Invite link visits', color: CLICKS_COLOR },
  { label: 'Signups', value: '186', hint: 'Signup rate: 7.8%', color: SIGNUPS_COLOR },
  {
    label: 'Conversions',
    value: '24',
    hint: 'Conversion rate: 12.9%',
    color: CONVERSIONS_COLOR,
  },
] as const

const PENDING_REWARDS_LABEL = '3 pending rewards'

const LINKS = [
  { name: 'Twitter launch', id: 'tw-launch', clicks: '1.1K' },
  { name: 'Dev.to article', id: 'devto-guide', clicks: '842' },
  { name: 'Discord share', id: 'discord-q1', clicks: '418' },
] as const

/** Abstract static series for the funnel chart (clicks → signups → conversions). */
const CHART_SERIES = [
  { clicks: 28, signups: 8, conversions: 2 },
  { clicks: 36, signups: 11, conversions: 3 },
  { clicks: 32, signups: 10, conversions: 2 },
  { clicks: 44, signups: 14, conversions: 4 },
  { clicks: 40, signups: 12, conversions: 3 },
  { clicks: 52, signups: 18, conversions: 5 },
  { clicks: 48, signups: 16, conversions: 4 },
  { clicks: 58, signups: 20, conversions: 6 },
  { clicks: 54, signups: 19, conversions: 5 },
  { clicks: 62, signups: 22, conversions: 7 },
  { clicks: 56, signups: 18, conversions: 5 },
  { clicks: 68, signups: 24, conversions: 8 },
] as const

function seriesToPath(
  values: readonly number[],
  width: number,
  height: number,
  max: number,
): string {
  if (values.length === 0) return ''
  const step = width / Math.max(values.length - 1, 1)
  return values
    .map((value, index) => {
      const x = index * step
      const y = height - (value / max) * height
      return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`
    })
    .join(' ')
}

function FunnelChart({ className }: { className?: string }) {
  const width = 560
  const height = 120
  const max = Math.max(...CHART_SERIES.map((point) => point.clicks)) * 1.1
  const clicks = CHART_SERIES.map((point) => point.clicks)
  const signups = CHART_SERIES.map((point) => point.signups)
  const conversions = CHART_SERIES.map((point) => point.conversions)

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={cn('h-full min-h-0 w-full', className)}
      aria-hidden
    >
      <defs>
        <linearGradient id="affiliates-preview-clicks" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={CLICKS_COLOR} stopOpacity="0.18" />
          <stop offset="100%" stopColor={CLICKS_COLOR} stopOpacity="0" />
        </linearGradient>
      </defs>
      {[0.25, 0.5, 0.75].map((line) => (
        <line
          key={line}
          x1="0"
          x2={width}
          y1={height * line}
          y2={height * line}
          stroke="hsl(var(--border))"
          strokeDasharray="3 3"
        />
      ))}
      <path
        d={`${seriesToPath(clicks, width, height, max)} L${width},${height} L0,${height} Z`}
        fill="url(#affiliates-preview-clicks)"
      />
      <path
        d={seriesToPath(clicks, width, height, max)}
        fill="none"
        stroke={CLICKS_COLOR}
        strokeWidth="1.5"
      />
      <path
        d={seriesToPath(signups, width, height, max)}
        fill="none"
        stroke={SIGNUPS_COLOR}
        strokeWidth="1.5"
      />
      <path
        d={seriesToPath(conversions, width, height, max)}
        fill="none"
        stroke={CONVERSIONS_COLOR}
        strokeWidth="1.5"
      />
    </svg>
  )
}

function LegendDot({ label, color }: { label: string; color: string }) {
  const t = useT()
  return (
    <div className="flex items-center gap-1.5">
      <span
        className="h-1.5 w-1.5 rounded-full"
        style={{ backgroundColor: color }}
        aria-hidden
      />
      <span className="text-[10px] text-muted-foreground">{t(label)}</span>
    </div>
  )
}

/** Decorative, non-interactive abstract of the Affiliates console dashboard. */
export function DashboardPreview({ className }: { className?: string }) {
  const t = useT()

  return (
    <div
      className={cn(
        'overflow-hidden rounded-xl border border-border bg-card/50 shadow-sm',
        className,
      )}
      aria-hidden
    >
      <div className="flex items-start justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
        <div className="min-w-0">
          <p className="text-[13px] font-semibold text-foreground">
            {t('Affiliates program')}
          </p>
          <p className="mt-1 text-[11px] text-muted-foreground">
            {t('Track links, referrals, and rewards')}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          <span className="rounded-md border border-border bg-background px-2 py-1 text-[10px] text-muted-foreground">
            {t('All links')}
          </span>
          <span className="hidden rounded-md border border-border bg-background px-2 py-1 text-[10px] text-muted-foreground sm:inline">
            {t('Last 30 days')}
          </span>
        </div>
      </div>

      <div className="flex items-center justify-between gap-3 border-b border-border bg-muted/30 px-4 py-3 sm:px-5">
        <div className="flex min-w-0 items-center gap-2.5">
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border bg-background">
            <Gift className="h-3.5 w-3.5 text-muted-foreground" />
          </div>
          <div className="min-w-0">
            <p className="text-[13px] font-semibold tabular-nums text-foreground">
              $45{' '}
              <span className="font-medium text-muted-foreground">
                {t('ready to claim')}
              </span>
            </p>
            <p className="text-[11px] text-muted-foreground">
              {t(PENDING_REWARDS_LABEL)}
            </p>
          </div>
        </div>
        <span className="rounded-md bg-foreground px-2.5 py-1 text-[11px] font-medium text-background">
          {t('Claim')}
        </span>
      </div>

      <div className="grid grid-cols-3 divide-x divide-border border-b border-border">
        {METRICS.map((metric) => (
          <div key={metric.label} className="min-w-0 px-3 py-3 sm:px-4 sm:py-3.5">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t(metric.label)}
            </p>
            <p className="mt-1 text-[18px] font-semibold tabular-nums tracking-tight text-foreground sm:text-[20px]">
              {metric.value}
            </p>
            <p className="mt-0.5 truncate text-[10px] text-muted-foreground">
              {t(metric.hint)}
            </p>
          </div>
        ))}
      </div>

      <div className="grid gap-0 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] lg:items-stretch">
        <div className="flex min-h-[12rem] flex-col border-b border-border px-4 py-3 sm:px-5 lg:min-h-0 lg:border-b-0 lg:border-e">
          <div className="mb-2 flex shrink-0 flex-wrap items-center justify-between gap-2">
            <p className="text-[11px] font-medium text-foreground">
              {t('Funnel over time')}
            </p>
            <div className="flex items-center gap-2.5">
              <LegendDot label="Clicks" color={CLICKS_COLOR} />
              <LegendDot label="Signups" color={SIGNUPS_COLOR} />
              <LegendDot label="Conversions" color={CONVERSIONS_COLOR} />
            </div>
          </div>
          <div className="min-h-0 flex-1">
            <FunnelChart />
          </div>
        </div>

        <div className="px-4 py-3 sm:px-5">
          <p className="mb-2 text-[11px] font-medium text-foreground">
            {t('Invite links')}
          </p>
          <div className="space-y-1.5">
            {LINKS.map((link) => (
              <div
                key={link.id}
                className="flex items-center justify-between gap-3 rounded-lg border border-border/70 bg-muted/20 px-3 py-2"
              >
                <div className="min-w-0">
                  <p className="truncate text-[12px] font-medium text-foreground">
                    {link.name}
                  </p>
                  <p className="truncate font-mono text-[10px] text-muted-foreground">
                    /i/{link.id}
                  </p>
                </div>
                <p className="shrink-0 text-[11px] tabular-nums text-muted-foreground">
                  {link.clicks} {t('clicks')}
                </p>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
