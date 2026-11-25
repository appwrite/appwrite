import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowDown, Play } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  isFilmPlayerRevealed,
  isLaunchEventDayLocked,
  type InitDisplayEvent,
  type LaunchEventDay,
  type LaunchEventDayFilm,
} from '@/lib/init/types'
import { INIT_EVENT_CATALOG } from '@/lib/init/init-event-catalog'
import { scrollToInitDayCard } from '@/lib/init/scroll-to-day-card'
import { InitFilmDialog, prefetchInitFilms } from './InitFilmDialog'

interface Reveal {
  label: string
  /** The product the film launches, beside the label on wider screens. */
  detail?: string
  title: string
  films: LaunchEventDayFilm[]
  /** The films say they play on Appwrite Videos, once that day is revealed. */
  poweredBy: boolean
  /** The day card the secondary button scrolls to. */
  day: number
  dayLabel: string
}

/**
 * What the hero's main button plays as the week goes by: today's reveal while
 * the event runs, the latest one revealed on a day without a launch, and
 * every film once the week is over.
 */
export function resolveInitReveal(event: InitDisplayEvent): Reveal | null {
  const revealed = event.days.filter(
    (day): day is LaunchEventDay =>
      !isLaunchEventDayLocked(day) && Boolean(day.films?.length),
  )
  if (revealed.length === 0) return null

  const lastDay = Math.max(...event.days.map((day) => day.day))
  if (event.isRecapMode || event.currentDay > lastDay) {
    return {
      label: 'Watch the launches',
      title: `${INIT_EVENT_CATALOG[event.id]?.editionLabel ?? 'Init'} launches`,
      films: revealed.flatMap((day) => day.films ?? []),
      poweredBy: isFilmPlayerRevealed(event),
      day: revealed[0]!.day,
      dayLabel: 'Browse the launches',
    }
  }

  const latest = revealed[revealed.length - 1]!
  const films = latest.films ?? []
  return {
    label:
      latest.day === event.currentDay
        ? "Watch today's reveal"
        : `Watch the day ${latest.day} reveal`,
    detail: films.map((film) => film.label).join(' & '),
    title: latest.title,
    films,
    poweredBy: isFilmPlayerRevealed(event),
    day: latest.day,
    dayLabel: `Go to day ${latest.day}`,
  }
}

/**
 * The hero's buttons once a day is revealed: play its launch film on the storm
 * stage, or go to its card on the page.
 */
export function InitRevealCta({ reveal }: { reveal: Reveal }) {
  const [open, setOpen] = useState(false)
  const queryClient = useQueryClient()
  const prefetch = () => prefetchInitFilms(queryClient, reveal.films)

  return (
    <>
      <Button
        variant="brandCta"
        size="lg"
        className="h-11 gap-2.5 ps-2 pe-5 text-[14px] shadow-[0_10px_40px_-12px_var(--brand-cta)]"
        onClick={() => setOpen(true)}
        onPointerEnter={prefetch}
        onFocus={prefetch}
      >
        <span className="flex size-7 items-center justify-center rounded-full bg-white text-[var(--brand-cta)]">
          <Play className="ms-0.5 size-3.5 fill-current" aria-hidden />
        </span>
        {reveal.label}
        {reveal.detail ? (
          <span className="hidden font-normal opacity-80 sm:inline">
            · {reveal.detail}
          </span>
        ) : null}
      </Button>
      <Button
        variant="outline"
        size="lg"
        className="h-11 gap-2 bg-background/40 px-5 text-[14px] backdrop-blur-sm"
        onClick={() => scrollToInitDayCard(reveal.day)}
      >
        {reveal.dayLabel}
        <ArrowDown className="size-4 opacity-70" aria-hidden />
      </Button>
      <InitFilmDialog
        title={reveal.title}
        films={reveal.films}
        poweredBy={reveal.poweredBy}
        open={open}
        onOpenChange={setOpen}
      />
    </>
  )
}
