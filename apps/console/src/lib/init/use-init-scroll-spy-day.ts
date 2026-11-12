import { useEffect, useMemo, useState } from 'react'
import { getInitDayCardId, INIT_DAY_CARD_SCROLL_OFFSET_PX } from './scroll-to-day-card'

/** Sticky collapsed Init bar height (px). */
export const INIT_COLLAPSED_HEADER_HEIGHT_PX = 56

/** Matches day card `scroll-mt-28` - anchor line for scroll-spy. */
export const INIT_DAY_SCROLL_SPY_OFFSET_PX = INIT_DAY_CARD_SCROLL_OFFSET_PX

export function useInitScrollSpyDay(dayNumbers: number[]) {
  const sortedDays = useMemo(
    () => [...dayNumbers].sort((a, b) => a - b),
    [dayNumbers],
  )
  const [activeDay, setActiveDay] = useState(sortedDays[0] ?? 1)

  useEffect(() => {
    setActiveDay(sortedDays[0] ?? 1)
  }, [sortedDays])

  useEffect(() => {
    const main = document.getElementById('main-content')
    if (!main || sortedDays.length === 0) return

    let frame = 0

    const update = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => {
        const anchor =
          main.getBoundingClientRect().top + INIT_DAY_SCROLL_SPY_OFFSET_PX
        let current = sortedDays[0]
        for (const day of sortedDays) {
          const el = document.getElementById(getInitDayCardId(day))
          if (!el) continue
          if (el.getBoundingClientRect().top <= anchor) {
            current = day
          }
        }
        setActiveDay(current)
      })
    }

    update()
    main.addEventListener('scroll', update, { passive: true })
    window.addEventListener('resize', update)

    const resizeObserver = new ResizeObserver(update)
    resizeObserver.observe(main)

    return () => {
      cancelAnimationFrame(frame)
      main.removeEventListener('scroll', update)
      window.removeEventListener('resize', update)
      resizeObserver.disconnect()
    }
  }, [sortedDays])

  return activeDay
}
