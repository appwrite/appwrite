import { INIT_PRIZES_SECTION_ID } from '@/lib/init/init-section-ids'

/** Matches day card `scroll-mt-28` anchor offset (px). */
export const INIT_DAY_CARD_SCROLL_OFFSET_PX = 112

export function getInitDayCardId(day: number): string {
  return `day-${day}`
}

function scrollMainToElementId(elementId: string) {
  if (typeof document === 'undefined') return
  const el = document.getElementById(elementId)
  const main = document.getElementById('main-content')
  if (!el || !main) return

  const mainRect = main.getBoundingClientRect()
  const elRect = el.getBoundingClientRect()
  const targetTop =
    main.scrollTop + elRect.top - mainRect.top - INIT_DAY_CARD_SCROLL_OFFSET_PX

  main.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' })
}

export function scrollToInitDayCard(day: number) {
  scrollMainToElementId(getInitDayCardId(day))
}

export function scrollToInitPrizesSection() {
  scrollMainToElementId(INIT_PRIZES_SECTION_ID)
}

export function scrollToInitDayFromHash() {
  if (typeof window === 'undefined') return
  const hash = window.location.hash.slice(1)
  const match = hash.match(/^day-(\d+)$/)
  if (!match) return
  scrollToInitDayCard(Number(match[1]))
}
