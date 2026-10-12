import { useEffect, useState } from 'react'
import {
  getPageSurfaceScrollState,
  subscribePageSurfaceScroll,
} from '@/lib/layout/marketing-document-scroll'

const MIN_FOLD_SCROLL_PX = 64
const FOLD_SCROLL_RATIO = 0.08

export function getHomeFoldScrollThreshold(foldHeight: number): number {
  return Math.max(MIN_FOLD_SCROLL_PX, foldHeight * FOLD_SCROLL_RATIO)
}

export function hasPassedHomeFold(
  scrollTop: number,
  foldHeight: number,
): boolean {
  return scrollTop >= getHomeFoldScrollThreshold(foldHeight)
}

/**
 * True once the homepage has moved past the hero fold.
 * Used to preload below-the-fold heavy assets (e.g. network globe) early.
 *
 * Marketing pages scroll the document; console pages scroll `#main-content`.
 */
export function useHomeFoldPassed() {
  const [passedFold, setPassedFold] = useState(false)

  useEffect(() => {
    if (typeof window === 'undefined') return

    const update = () => {
      const { scrollTop, viewportHeight } = getPageSurfaceScrollState()
      if (hasPassedHomeFold(scrollTop, viewportHeight)) {
        setPassedFold(true)
      }
    }

    update()
    // MarketingSiteLayout enables document scroll in a parent effect. Re-read
    // after that commit so a restored or hash scroll position is not missed.
    const rafId = window.requestAnimationFrame(update)
    const unsubscribe = subscribePageSurfaceScroll(update)

    return () => {
      window.cancelAnimationFrame(rafId)
      unsubscribe()
    }
  }, [])

  return passedFold
}
