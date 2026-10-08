import { resetConsoleShellDocumentScroll } from '@/lib/utils'
import {
  getPageSurfaceOffsetTop,
  scrollPageSurfaceTo,
} from '@/lib/layout/marketing-document-scroll'

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

  const el = document.getElementById(sectionId)
  if (!el) return

  resetConsoleShellDocumentScroll()

  const targetTop =
    getPageSurfaceOffsetTop(el) - MARKETING_SECTION_SCROLL_OFFSET_PX
  scrollPageSurfaceTo(targetTop, behavior)

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
