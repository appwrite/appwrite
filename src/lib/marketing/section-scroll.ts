import { resetConsoleShellDocumentScroll } from '@/lib/utils'

/** Matches marketing section `scroll-mt-28` offset (px). */
export const MARKETING_SECTION_SCROLL_OFFSET_PX = 112

export const HOME_SCALE_SECTION_ID = 'scale'

export function isHomeHashTarget(hash: string): boolean {
  return hash === HOME_SCALE_SECTION_ID
}

export function scrollToMarketingSection(
  sectionId: string,
  behavior: ScrollBehavior = 'smooth',
) {
  if (typeof document === 'undefined') return

  const main = document.getElementById('main-content')
  const el = document.getElementById(sectionId)
  if (!main || !el) return

  resetConsoleShellDocumentScroll()

  const mainRect = main.getBoundingClientRect()
  const elRect = el.getBoundingClientRect()
  const targetTop =
    main.scrollTop +
    elRect.top -
    mainRect.top -
    MARKETING_SECTION_SCROLL_OFFSET_PX

  main.scrollTo({ top: Math.max(0, targetTop), behavior })

  if (typeof window !== 'undefined') {
    const nextHash = `#${sectionId}`
    if (window.location.hash !== nextHash) {
      window.history.replaceState(null, '', nextHash)
      resetConsoleShellDocumentScroll()
    }
  }
}

export function scrollToHomeHashFromLocation(behavior: ScrollBehavior = 'auto') {
  if (typeof window === 'undefined') return false

  const hash = window.location.hash.slice(1)
  if (!isHomeHashTarget(hash)) return false

  scrollToMarketingSection(hash, behavior)
  return true
}
