/** Matches timeline year marker `scroll-mt-28` offset (px). */
export const TIMELINE_YEAR_SCROLL_OFFSET_PX = 112

export function getTimelineYearAnchorId(year: number) {
  return `year-${year}`
}

export function scrollToTimelineYear(year: number) {
  if (typeof document === 'undefined') return

  const main = document.getElementById('main-content')
  if (!main) return

  const el = Array.from(
    main.querySelectorAll<HTMLElement>(`[data-timeline-year="${year}"]`),
  ).find((node) => node.getClientRects().length > 0)
  if (!el) return

  const mainRect = main.getBoundingClientRect()
  const elRect = el.getBoundingClientRect()
  const targetTop =
    main.scrollTop +
    elRect.top -
    mainRect.top -
    TIMELINE_YEAR_SCROLL_OFFSET_PX

  main.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' })

  if (typeof window !== 'undefined') {
    window.history.replaceState(null, '', `#${getTimelineYearAnchorId(year)}`)
  }
}

export function scrollToTimelineYearFromHash() {
  if (typeof window === 'undefined') return

  const hash = window.location.hash.slice(1)
  const match = hash.match(/^year-(20\d{2})$/)
  if (!match) return

  scrollToTimelineYear(Number(match[1]))
}
