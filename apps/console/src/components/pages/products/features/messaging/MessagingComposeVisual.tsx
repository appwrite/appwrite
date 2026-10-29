import { BellRing, CalendarClock, Check, Hash, KeyRound, Mail, MessageSquareText, ShieldAlert } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import {
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const CHANNELS: { id: string; label: string; icon: LucideIcon; active?: boolean }[] = [
  { id: 'email', label: 'Email', icon: Mail, active: true },
  { id: 'sms', label: 'SMS', icon: MessageSquareText },
  { id: 'push', label: 'Push', icon: BellRing },
]

const WEEK_DAYS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'] as const
const TODAY = 11
const SCHEDULED_DAY = 15

const DELIVERY_LOG: {
  id: string
  title: string
  icon: LucideIcon
  channel: string
  time: string
  scheduled?: boolean
}[] = [
  { id: 'digest', title: 'Weekly product digest', icon: CalendarClock, channel: 'Email', time: 'Mon 9:00', scheduled: true },
  { id: 'otp', title: 'OTP verification', icon: KeyRound, channel: 'SMS', time: '12:41' },
  { id: 'reset', title: 'Password reset', icon: Mail, channel: 'Email', time: '12:38' },
  { id: 'alert', title: 'Account alerts', icon: ShieldAlert, channel: 'Push', time: '12:30' },
]

function StageConnector({ delayMs }: { delayMs: number }) {
  return (
    <div aria-hidden>
      <ArtConnector travel travelDelayMs={delayMs} className="hidden w-full lg:block" />
      <ArtConnector orientation="vertical" className="mx-auto h-8 lg:hidden" />
    </div>
  )
}

function ComposerWindow() {
  const t = useT()
  return (
    <ArtWindow
      className="product-hero-rise"
      style={riseStyle(60)}
      title={t('Compose message')}
      bodyClassName="space-y-3 p-4"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-[13px] font-semibold text-foreground">{t('Weekly product digest')}</p>
        <div className="flex gap-0.5 rounded-lg border border-border bg-muted/30 p-0.5">
          {CHANNELS.map((channel) => {
            const Icon = channel.icon
            return (
              <span
                key={channel.id}
                className={cn(
                  'inline-flex items-center gap-1 rounded-md px-2 py-1 text-[10px] font-medium',
                  channel.active
                    ? 'bg-background text-foreground shadow-sm dark:bg-card'
                    : 'text-muted-foreground',
                )}
              >
                <Icon className="size-3" aria-hidden />
                {t(channel.label)}
              </span>
            )
          })}
        </div>
      </div>

      <div>
        <p className="mb-1 text-[10px] font-medium text-muted-foreground">{t('Subject')}</p>
        <div className="rounded-md border border-border bg-background px-2.5 py-1.5 text-[12px] text-foreground dark:bg-card">
          {t("What's new in Acme this week")}
        </div>
      </div>

      <div>
        <p className="mb-1 text-[10px] font-medium text-muted-foreground">{t('Content')}</p>
        <div className="rounded-md border border-border bg-muted/15 px-2.5 py-2 text-[11px] leading-relaxed text-muted-foreground">
          {t('Ship notes, feature highlights, and links for your subscribers.')}
          <span
            className="ms-0.5 inline-block h-3 w-px translate-y-0.5 bg-[var(--tone-ink)] animate-[ai-mock-cursor-blink_1s_step-end_infinite] motion-reduce:animate-none"
            aria-hidden
          />
        </div>
      </div>

      <div className="flex items-center gap-2 rounded-md border border-[rgb(var(--tone-rgb)/0.35)] bg-[rgb(var(--tone-rgb)/0.06)] px-2.5 py-2">
        <Hash className="size-3.5 shrink-0 text-[var(--tone-ink)]" aria-hidden />
        <span dir="ltr" className="truncate font-mono text-[11px] text-foreground">
          weekly-digest
        </span>
        <span className="ms-auto shrink-0 text-[10px] text-muted-foreground">
          {t('Topic')} · <span dir="ltr">3,401</span> {t('targets')}
        </span>
      </div>
    </ArtWindow>
  )
}

function ScheduleStage() {
  const t = useT()
  return (
    <ArtPanel innerClassName="p-3.5" delayMs={400} float floatDelayMs={300}>
      <div className="flex items-center gap-2.5">
        <ArtIconBadge icon={CalendarClock} />
        <div className="min-w-0">
          <p className="text-[12px] font-semibold text-foreground">{t('Scheduled send')}</p>
          <p dir="ltr" className="truncate text-start font-mono text-[10px] text-muted-foreground">
            Mon, 9:00 AM · America/New_York
          </p>
        </div>
      </div>

      <div dir="ltr" className="mt-3 grid grid-cols-7 gap-1 text-center" aria-hidden>
        {WEEK_DAYS.map((day, index) => (
          <span key={index} className="text-[9px] font-medium text-muted-foreground/70">
            {day}
          </span>
        ))}
        {Array.from({ length: 14 }, (_, index) => {
          const day = index + 8
          const selected = day === SCHEDULED_DAY
          return (
            <span
              key={day}
              className={cn(
                'flex h-6 items-center justify-center rounded-md font-mono text-[10px]',
                selected
                  ? 'bg-[var(--tone-ink)] font-semibold text-background'
                  : day < TODAY
                    ? 'text-muted-foreground/50'
                    : 'text-foreground/80',
              )}
            >
              {day}
            </span>
          )
        })}
      </div>

      <div className="mt-3 flex gap-2">
        <span className="inline-flex h-7 flex-1 items-center justify-center rounded-md bg-foreground px-2.5 text-[11px] font-medium text-background">
          {t('Schedule message')}
        </span>
        <span className="inline-flex h-7 items-center justify-center rounded-md border border-border bg-background px-2.5 text-[11px] font-medium text-foreground dark:bg-card">
          {t('Send now')}
        </span>
      </div>
    </ArtPanel>
  )
}

function DeliveryStage() {
  const t = useT()
  return (
    <div className="space-y-2">
      <p
        className="product-hero-rise px-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
        style={riseStyle(650)}
      >
        {t('Delivery')}
      </p>
      {DELIVERY_LOG.map((entry, index) => (
        <ArtPanel
          key={entry.id}
          delayMs={750 + index * 140}
          innerClassName={cn(
            'flex items-center gap-2.5 px-3 py-2',
            entry.scheduled && 'border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
          )}
        >
          <ArtIconBadge icon={entry.icon} tone={entry.scheduled ? 'primary' : 'neutral'} className="size-6" />
          <div className="min-w-0 flex-1">
            <p className="truncate text-[12px] font-medium text-foreground">{t(entry.title)}</p>
            <p className="text-[10px] text-muted-foreground">{t(entry.channel)}</p>
          </div>
          {entry.scheduled ? (
            <span dir="ltr" className="shrink-0 font-mono text-[10px] font-medium text-[var(--tone-ink)]">
              {entry.time}
            </span>
          ) : (
            <span className="flex shrink-0 items-center gap-1.5">
              <span dir="ltr" className="hidden font-mono text-[10px] text-muted-foreground sm:inline">
                {entry.time}
              </span>
              <span className="flex items-center gap-1 rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:text-emerald-400">
                <Check className="size-2.5" aria-hidden />
                {t('Delivered')}
              </span>
            </span>
          )}
        </ArtPanel>
      ))}
    </div>
  )
}

export function MessagingComposeVisual() {
  return (
    <div className="mx-auto grid w-full max-w-md items-center lg:max-w-none lg:grid-cols-[minmax(0,1.25fr)_4rem_minmax(0,0.85fr)_4rem_minmax(0,1fr)]">
      <ComposerWindow />
      <StageConnector delayMs={0} />
      <ScheduleStage />
      <StageConnector delayMs={900} />
      <DeliveryStage />
    </div>
  )
}
