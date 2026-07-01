import { resetConsoleShellDocumentScroll } from '@/lib/utils'
import { PRICING_DATABASE_ANCHOR_ID } from './dedicated-databases'
import { comparisonPageSections } from './comparison-sections'

export const PRICING_COMPARE_ANCHOR_ID = 'compare'

/** Matches comparison table `scroll-mt-28` offset (px). */
export const COMPARISON_SECTION_SCROLL_OFFSET_PX = 112

export function isPricingHashTarget(hash: string): boolean {
  if (!hash) return false
  if (hash === PRICING_COMPARE_ANCHOR_ID) return true
  if (hash === PRICING_DATABASE_ANCHOR_ID) return true
  return comparisonPageSections.some((section) => section.id === hash)
}

/** Keep shell/document at top; optional reset of the main scroll container before programmatic scroll. */
export function resetPricingPageScrollContainers(resetMain = false) {
  if (typeof window === 'undefined') return

  resetConsoleShellDocumentScroll()

  if (!resetMain) return

  const main = document.getElementById('main-content')
  if (main) main.scrollTop = 0
}

export function scrollToComparisonSection(
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
    COMPARISON_SECTION_SCROLL_OFFSET_PX

  main.scrollTo({ top: Math.max(0, targetTop), behavior })

  if (typeof window !== 'undefined') {
    const nextHash = `#${sectionId}`
    if (window.location.hash !== nextHash) {
      // Updating the hash scrolls `.root-container` asynchronously; the pricing
      // page locks that container at scrollTop 0 while mounted.
      window.history.replaceState(null, '', nextHash)
      resetConsoleShellDocumentScroll()
    }
  }
}

export function applyPricingHashScroll(options?: {
  behavior?: ScrollBehavior
  resetMain?: boolean
}) {
  if (typeof window === 'undefined') return false

  const hash = window.location.hash.slice(1)
  if (!isPricingHashTarget(hash)) return false

  const { behavior = 'smooth', resetMain = false } = options ?? {}

  resetPricingPageScrollContainers(resetMain)

  const scroll = () => scrollToComparisonSection(hash, behavior)

  if (resetMain) {
    requestAnimationFrame(() => requestAnimationFrame(scroll))
  } else {
    scroll()
  }

  return true
}

export function scrollToComparisonSectionFromHash() {
  applyPricingHashScroll({ behavior: 'auto', resetMain: true })
}
