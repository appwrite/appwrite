import {
  Bell,
  Gauge,
  Home,
  Layers,
  Newspaper,
  Settings,
  type LucideIcon,
} from 'lucide-react'
import type { CSSProperties, ReactNode } from 'react'
import { ArtAppwriteMark } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/**
 * Drawn pieces of the Beep app for the product page: the phone, its tab bar,
 * alert pills, and pushes. Screens follow the app's real layout (Home,
 * Projects, Alerts, Monitors, Updates, and Settings behind the gear) in the
 * page's own colors, light or dark with the site, and are drawn rather than
 * cropped from screenshots so every edge stays crisp.
 */

export type BeepTab = 'home' | 'projects' | 'alerts' | 'monitors' | 'updates'

const TABS: { id: BeepTab; label: string; icon: LucideIcon }[] = [
  { id: 'home', label: 'Home', icon: Home },
  { id: 'projects', label: 'Projects', icon: Layers },
  { id: 'alerts', label: 'Alerts', icon: Bell },
  { id: 'monitors', label: 'Monitors', icon: Gauge },
  { id: 'updates', label: 'Updates', icon: Newspaper },
]

/** Phone with one outline, a status bar, and an optional tab bar. */
export function BeepPhone({
  children,
  tab,
  alertCount,
  device = 'iphone',
  className,
  screenClassName,
  style,
}: {
  children: ReactNode
  /** Active tab, or no tab bar when omitted (lock screen, pushed screens). */
  tab?: BeepTab
  /** Badge on the Alerts tab: firing alerts nobody acknowledged. */
  alertCount?: number
  /** Camera cutout: a pill on iPhone, a punch hole on Android. */
  device?: 'iphone' | 'android'
  className?: string
  screenClassName?: string
  style?: CSSProperties
}) {
  const t = useT()
  return (
    <div
      dir="ltr"
      className={cn(
        'product-tone-shadow relative w-[284px] max-w-full rounded-[2.85rem] border border-border bg-background p-[7px] dark:border-white/12 dark:bg-[color-mix(in_srgb,var(--card)_92%,white_4%)]',
        className,
      )}
      style={style}
    >
      <div
        className={cn(
          'relative flex h-[572px] flex-col overflow-hidden rounded-[2.4rem] bg-muted/40 dark:bg-background',
          screenClassName,
        )}
      >
        <div className="relative flex h-11 shrink-0 items-center justify-between px-6 text-[11.5px] font-semibold text-foreground">
          <span className="tabular-nums">9:41</span>
          {device === 'iphone' ? (
            <span
              className="absolute left-1/2 top-2.5 h-[22px] w-[78px] -translate-x-1/2 rounded-full bg-black"
              aria-hidden
            />
          ) : (
            <span
              className="absolute left-1/2 top-3 size-[14px] -translate-x-1/2 rounded-full bg-black"
              aria-hidden
            />
          )}
          <StatusIcons />
        </div>
        <div className="relative min-h-0 flex-1 overflow-hidden">
          {children}
        </div>
        {tab ? (
          <div
            className="pointer-events-none absolute inset-x-0 bottom-0 z-[1] h-24 bg-gradient-to-t from-[color-mix(in_srgb,var(--muted)_40%,var(--background))] to-transparent dark:from-background"
            aria-hidden
          />
        ) : null}
        {tab ? (
          <div className="pointer-events-none absolute inset-x-2.5 bottom-3 z-[2] flex items-center justify-between rounded-[1.4rem] border border-border/70 bg-background/90 px-1 py-1 shadow-[0_8px_24px_-12px_rgb(0_0_0/0.35)] backdrop-blur dark:bg-card/90">
            {TABS.map((item) => {
              const Icon = item.icon
              const active = item.id === tab
              return (
                <span
                  key={item.id}
                  className={cn(
                    'relative flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-[1rem] py-1 text-[8.5px] font-medium',
                    active
                      ? 'bg-[rgb(var(--tone-rgb)/0.12)] text-[var(--tone-ink)]'
                      : 'text-muted-foreground',
                  )}
                >
                  <Icon
                    className="size-[15px]"
                    strokeWidth={active ? 2.25 : 1.75}
                    aria-hidden
                  />
                  {t(item.label)}
                  {item.id === 'alerts' && alertCount ? (
                    <span className="absolute end-2 top-0 flex size-3.5 items-center justify-center rounded-full bg-[var(--brand-cta)] text-[8px] font-semibold tabular-nums text-white">
                      {alertCount}
                    </span>
                  ) : null}
                </span>
              )
            })}
          </div>
        ) : null}
      </div>
    </div>
  )
}

function StatusIcons() {
  return (
    <span className="flex items-center gap-1" aria-hidden>
      <svg viewBox="0 0 17 11" className="h-[9px] w-auto fill-current">
        <rect x="0" y="7" width="3" height="4" rx="0.8" />
        <rect x="4.5" y="5" width="3" height="6" rx="0.8" />
        <rect x="9" y="2.5" width="3" height="8.5" rx="0.8" />
        <rect x="13.5" y="0" width="3" height="11" rx="0.8" />
      </svg>
      <svg viewBox="0 0 15 11" className="h-[9px] w-auto fill-current">
        <path d="M7.5 2.3c2.2 0 4.2.9 5.7 2.3l1.1-1.1A9.6 9.6 0 0 0 7.5.7 9.6 9.6 0 0 0 .7 3.5l1.1 1.1a8 8 0 0 1 5.7-2.3Zm0 3.2c1.3 0 2.5.5 3.4 1.3l1.1-1.1a6.5 6.5 0 0 0-9 0l1.1 1.1c.9-.8 2.1-1.3 3.4-1.3Zm0 3.1c.5 0 .9.2 1.2.5L7.5 10.3 6.3 9.1c.3-.3.7-.5 1.2-.5Z" />
      </svg>
      <span className="relative flex h-[10px] w-[21px] items-center rounded-[3px] border border-current/40 p-[1.5px]">
        <span className="h-full w-[80%] rounded-[1.5px] bg-current" />
      </span>
    </span>
  )
}

/** Screen header: the organization pill and settings, then a large title. */
export function BeepScreenHeader({
  title,
  subtitle,
  pill = 'Acme Inc',
  className,
}: {
  title: string
  subtitle?: string
  pill?: string | null
  className?: string
}) {
  const t = useT()
  return (
    <div className={cn('px-4 pt-1', className)}>
      <div className="flex items-center justify-between">
        {pill ? (
          <span className="rounded-full border border-border bg-background/80 px-2.5 py-1 text-[10px] font-medium text-foreground dark:bg-card">
            {pill}
          </span>
        ) : (
          <span />
        )}
        <span className="flex size-7 items-center justify-center rounded-full border border-border bg-background/80 text-muted-foreground dark:bg-card">
          <Settings className="size-3.5" aria-hidden />
        </span>
      </div>
      <p className="mt-3 font-aeonik-pro text-[24px] leading-none tracking-tight text-foreground">
        {t(title)}
      </p>
      {subtitle ? (
        <p className="mt-1.5 text-[10.5px] text-muted-foreground">
          {t(subtitle)}
        </p>
      ) : null}
    </div>
  )
}

/** Card surface inside a phone screen. */
export function BeepCard({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return (
    <div
      className={cn(
        'rounded-2xl border border-border/80 bg-background px-3 py-2.5 dark:bg-card',
        className,
      )}
    >
      {children}
    </div>
  )
}

export type BeepSeverity = 'critical' | 'error' | 'warning' | 'info'

export const BEEP_SEVERITY_LABEL: Record<BeepSeverity, string> = {
  critical: 'Critical',
  error: 'Error',
  warning: 'Warning',
  info: 'Info',
}

/** Severity colors, shared by pills, bars, and chart legends. */
export const BEEP_SEVERITY_CLASS: Record<
  BeepSeverity,
  { pill: string; bar: string; text: string }
> = {
  critical: {
    pill: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    bar: 'bg-rose-500',
    text: 'text-rose-600 dark:text-rose-400',
  },
  error: {
    pill: 'bg-orange-500/15 text-orange-600 dark:text-orange-400',
    bar: 'bg-orange-500',
    text: 'text-orange-600 dark:text-orange-400',
  },
  warning: {
    pill: 'bg-amber-400/20 text-amber-700 dark:text-amber-300',
    bar: 'bg-amber-400',
    text: 'text-amber-700 dark:text-amber-300',
  },
  info: {
    pill: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    bar: 'bg-sky-500',
    text: 'text-sky-700 dark:text-sky-300',
  },
}

export function BeepPill({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-[5px] px-1.5 py-px text-[9px] font-semibold leading-[14px]',
        className,
      )}
    >
      {children}
    </span>
  )
}

export function BeepSeverityPill({ severity }: { severity: BeepSeverity }) {
  const t = useT()
  return (
    <BeepPill className={BEEP_SEVERITY_CLASS[severity].pill}>
      {t(BEEP_SEVERITY_LABEL[severity])}
    </BeepPill>
  )
}

export function BeepFiringPill() {
  const t = useT()
  return (
    <BeepPill className="border border-rose-500/40 text-rose-600 dark:text-rose-400">
      {t('Firing')}
    </BeepPill>
  )
}

/** The Beep app icon: the Appwrite mark on its dark tile. */
export function BeepAppIcon({ className }: { className?: string }) {
  return (
    <span
      className={cn(
        'flex size-6 shrink-0 items-center justify-center rounded-[7px] bg-[#19191c]',
        className,
      )}
      aria-hidden
    >
      <ArtAppwriteMark className="size-[78%]" />
    </span>
  )
}

/** A push as it lands on the lock screen or in Notification Center. */
export function BeepPush({
  title,
  body,
  time,
  className,
  style,
}: {
  title: ReactNode
  body: ReactNode
  time: string
  className?: string
  style?: CSSProperties
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'flex items-start gap-2.5 rounded-[1.1rem] border border-border/70 bg-background/85 px-3 py-2.5 shadow-[0_10px_30px_-18px_rgb(0_0_0/0.45)] backdrop-blur dark:bg-card/85',
        className,
      )}
      style={style}
    >
      <BeepAppIcon className="mt-0.5 size-7 rounded-[8px]" />
      <div className="min-w-0 flex-1">
        <p className="flex items-start gap-2">
          <span className="line-clamp-2 min-w-0 flex-1 text-[11px] font-semibold leading-[14px] text-foreground">
            {title}
          </span>
          <span className="shrink-0 text-[9.5px] leading-[14px] text-muted-foreground">
            {t(time)}
          </span>
        </p>
        <p className="mt-0.5 line-clamp-2 text-[10.5px] leading-[14px] text-muted-foreground">
          {body}
        </p>
      </div>
    </div>
  )
}

/** A thin usage bar: how far the value is past (or toward) the threshold. */
export function BeepThresholdBar({
  percent,
  severity,
  className,
}: {
  percent: number
  severity: BeepSeverity
  className?: string
}) {
  return (
    <span
      className={cn(
        'block h-1 overflow-hidden rounded-full bg-muted',
        className,
      )}
      aria-hidden
    >
      <span
        className={cn(
          'block h-full rounded-full',
          BEEP_SEVERITY_CLASS[severity].bar,
        )}
        style={{ width: `${Math.max(4, Math.min(100, percent))}%` }}
      />
    </span>
  )
}

/** An on/off switch as drawn in Beep's settings and setup. */
export function BeepSwitch({ on }: { on: boolean }) {
  return (
    <span
      className={cn(
        'relative inline-flex h-[18px] w-[30px] shrink-0 items-center rounded-full transition-colors',
        on ? 'bg-emerald-500' : 'bg-muted-foreground/30',
      )}
      aria-hidden
    >
      <span
        className={cn(
          'absolute size-[14px] rounded-full bg-white shadow-sm transition-[inset-inline-start]',
          on ? 'start-[14px]' : 'start-[2px]',
        )}
      />
    </span>
  )
}

/** A grouped settings list: a caption above rows with a label, optional detail, and a trailing control. */
export function BeepSettingsGroup({
  title,
  rows,
  footer,
  className,
}: {
  title?: string
  rows: { label: ReactNode; detail?: ReactNode; trailing?: ReactNode }[]
  footer?: string
  className?: string
}) {
  const t = useT()
  return (
    <div className={className}>
      {title ? (
        <p className="px-1 pb-1 text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t(title)}
        </p>
      ) : null}
      <div className="divide-y divide-border/70 overflow-hidden rounded-2xl border border-border/80 bg-background dark:bg-card">
        {rows.map((row, index) => (
          <div key={index} className="flex items-center gap-2 px-3 py-2">
            <span className="min-w-0 flex-1">
              <span className="block truncate text-[10.5px] font-medium text-foreground">
                {row.label}
              </span>
              {row.detail ? (
                <span className="block truncate text-[9px] text-muted-foreground">
                  {row.detail}
                </span>
              ) : null}
            </span>
            {row.trailing}
          </div>
        ))}
      </div>
      {footer ? (
        <p className="px-1 pt-1.5 text-[8.5px] leading-[12px] text-muted-foreground">
          {t(footer)}
        </p>
      ) : null}
    </div>
  )
}

/** Bottom sheet over a phone screen, with a grabber. */
export function BeepSheet({
  children,
  className,
  style,
}: {
  children: ReactNode
  className?: string
  style?: CSSProperties
}) {
  return (
    <div
      className={cn(
        'absolute inset-x-0 bottom-0 z-[3] rounded-t-[1.6rem] border-t border-border bg-background px-3.5 pb-5 pt-2 shadow-[0_-16px_40px_-20px_rgb(0_0_0/0.5)] dark:bg-card',
        className,
      )}
      style={style}
    >
      <span
        className="mx-auto mb-2.5 block h-1 w-9 rounded-full bg-muted-foreground/30"
        aria-hidden
      />
      {children}
    </div>
  )
}

export type BeepAlertRowData = {
  title: string
  detail: string
  severity: BeepSeverity
  time: string
  budget?: boolean
  /** Usage reading against its threshold, e.g. "6.2 GB > 5 GB". */
  reading?: string
  /** How far past the threshold, for the bar. */
  percent?: number
  /** Failed uptime check result, e.g. "Unexpected status code · HTTP 503". */
  failure?: string
  latency?: string
}

/** One alert in the Alerts tab: pills, name, where it fired, and the reading or the check result. */
export function BeepAlertRow({
  alert,
  className,
}: {
  alert: BeepAlertRowData
  className?: string
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'rounded-2xl border border-border/80 bg-background px-3 py-2.5 dark:bg-card',
        className,
      )}
    >
      <div className="flex items-center gap-1">
        <BeepFiringPill />
        <BeepSeverityPill severity={alert.severity} />
        {alert.budget ? (
          <BeepPill className="bg-muted text-muted-foreground">
            {t('Budget')}
          </BeepPill>
        ) : null}
        <span className="ms-auto text-[8.5px] text-muted-foreground">
          {t(alert.time)}
        </span>
      </div>
      <p className="mt-1.5 text-[11.5px] font-semibold text-foreground">
        {t(alert.title)}
      </p>
      <p dir="ltr" className="truncate text-[9px] text-muted-foreground">
        {alert.detail}
      </p>
      {alert.reading ? (
        <>
          <p
            dir="ltr"
            className="mt-1.5 text-[10px] font-medium text-foreground"
          >
            {alert.reading}
          </p>
          <BeepThresholdBar
            className="mt-1.5"
            percent={alert.percent ?? 100}
            severity={alert.severity}
          />
        </>
      ) : null}
      {alert.failure ? (
        <p className="mt-1.5 flex items-baseline justify-between gap-2 text-[10px]">
          <span
            className={cn(
              'min-w-0 truncate font-medium',
              BEEP_SEVERITY_CLASS[alert.severity].text,
            )}
          >
            {t(alert.failure)}
          </span>
          {alert.latency ? (
            <span className="shrink-0 text-[9px] text-muted-foreground">
              {alert.latency}
            </span>
          ) : null}
        </p>
      ) : null}
    </div>
  )
}

/** The firing alerts used across the page art, from the app's demo organization. */
export const BEEP_FIRING_ALERTS: BeepAlertRowData[] = [
  {
    title: 'Egress spike',
    detail: 'Outbound bandwidth · storefront-prod',
    severity: 'critical',
    time: '4 min ago',
    reading: '6.2 GB > 5 GB',
    percent: 100,
  },
  {
    title: 'Checkout API health',
    detail: 'api.checkout.example.com/v1/health · checkout-api',
    severity: 'error',
    time: '12 min ago',
    failure: 'Unexpected status code · HTTP 503',
    latency: '412 ms',
  },
  {
    title: 'Budget at 100%',
    detail: 'Budget used · Organization budget',
    severity: 'critical',
    time: '18 min ago',
    budget: true,
    reading: '102% > 100%',
    percent: 100,
  },
]
