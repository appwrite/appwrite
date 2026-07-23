'use client'

import { useMemo } from 'react'
import { Link } from '@tanstack/react-router'
import { ArrowRight, Lock } from 'lucide-react'
import { InitDayCountdown } from '@/components/pages/init/_components/InitDayCountdown'
import { InitWordmark } from '@/components/pages/init/_components/InitWordmark'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { useT } from '@/lib/i18n/translate'
import { applyInitEventVisibility } from '@/lib/init/event-visibility'
import { getActiveLaunchEvent } from '@/lib/init/events'
import { isInitEventDuring } from '@/lib/init/org-promo-banner'
import { getInitDayCardId } from '@/lib/init/scroll-to-day-card'
import {
  isLaunchEventDayLocked,
  type LaunchEventDayView,
} from '@/lib/init/types'
import { CARD_LINK_HINT_CLASS } from '@/lib/link-styles'
import { cn } from '@/lib/utils'

function HomeInitDayCard({
  day,
  eventStartDate,
}: {
  day: LaunchEventDayView
  eventStartDate: string
}) {
  const t = useT()
  if (isLaunchEventDayLocked(day)) {
    return (
      <Link
        to="/init"
        hash={getInitDayCardId(day.day)}
        className={cn(
          'link-unstyled flex min-h-full flex-col rounded-xl border border-dashed border-border bg-muted/20 p-4 text-start transition-colors',
          'hover:bg-muted/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        )}
      >
        <div className="flex items-start justify-between gap-2">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Day')} {day.day} · {t(day.dateLabel)}
          </p>
          <Lock className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
        </div>
        <p className="mt-3 text-[13px] font-medium text-muted-foreground">
          {t('Coming soon')}
        </p>
        <InitDayCountdown
          eventStartDate={eventStartDate}
          dayNumber={day.day}
          size="sm"
          className="mt-2"
        />
        <p className="mt-2 text-[12px] leading-normal text-muted-foreground/80">
          {t('Unlocks on')} {t(day.dateLabel)}
        </p>
      </Link>
    )
  }

  return (
    <Link
      to="/init"
      hash={getInitDayCardId(day.day)}
      className={cn(
        'link-unstyled group flex min-h-full flex-col rounded-xl border bg-card/50 p-4 text-start transition-colors',
        'hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        day.isLive
          ? 'border-[color-mix(in_srgb,var(--brand-cta)_45%,var(--border))]'
          : 'border-border',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Day')} {day.day} · {t(day.dateLabel)}
        </p>
        {day.isLive ? (
          <Badge variant="error" className="text-[10px] shrink-0">
            {t('Live')}
          </Badge>
        ) : null}
      </div>

      <h3 className="mt-3 text-[15px] font-semibold leading-none text-foreground">
        {t(day.title)}
      </h3>
      <p className="mt-3 min-h-[2lh] flex-1 line-clamp-2 text-[12px] leading-normal text-muted-foreground">
        {t(day.description)}
      </p>
      <span className={cn('mt-4 text-[12px]', CARD_LINK_HINT_CLASS)}>
        {t('View day')} {day.day}
        <ArrowRight className="size-3.5" aria-hidden />
      </span>
    </Link>
  )
}

export function InitSection() {
  const t = useT()
  const { mockInitCurrentDay } = useDebugOverrides()

  const visible = useMemo(
    () => isInitEventDuring({ mockCurrentDay: mockInitCurrentDay }),
    [mockInitCurrentDay],
  )

  const event = useMemo(() => {
    if (!visible) return null

    const active = getActiveLaunchEvent()
    if (!active) return null

    return applyInitEventVisibility(active, { mockCurrentDay: mockInitCurrentDay })
  }, [visible, mockInitCurrentDay])

  if (!visible || !event) return null

  return (
    <section className="relative isolate overflow-hidden border-b border-border bg-background py-8 sm:py-10">
      <div
        className="absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] opacity-50"
        aria-hidden
      />
      <div className="relative mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className="flex items-center gap-2.5 overflow-hidden sm:gap-3">
          <InitWordmark className="shrink-0 text-[20px] text-foreground sm:text-[22px]" />
          <Badge variant="success" className="text-[10px] shrink-0">
            {t('Live')}
          </Badge>
          <span
            aria-hidden
            className="hidden shrink-0 text-muted-foreground/40 md:inline"
          >
            ·
          </span>
          <span className="hidden shrink-0 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground md:inline">
            {t(event.dateRangeLabel)}
          </span>
          <span
            aria-hidden
            className="hidden shrink-0 text-muted-foreground/40 min-[420px]:inline"
          >
            ·
          </span>
          <p className="min-w-0 flex-1 truncate text-[13px] text-muted-foreground max-[419px]:sr-only">
            {t('Launch week is live. Follow daily drops, live sessions, and giveaways.')}
          </p>
          <Button
            variant="brandCta"
            size="sm"
            className="h-8 shrink-0 px-3 text-[13px]"
            asChild
          >
            <Link to="/init" {...analyticsAttrs('home-join-init')}>
              {t('Join Init')}
              <ArrowRight className="size-3.5" />
            </Link>
          </Button>
        </div>

        <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {event.days.map((day) => (
            <HomeInitDayCard
              key={day.day}
              day={day}
              eventStartDate={event.startDate}
            />
          ))}
        </div>
      </div>
    </section>
  )
}
