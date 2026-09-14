import { useEffect, useState } from 'react'

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
 * True once the homepage main scroll container has moved past the hero fold.
 * Used to preload below-the-fold heavy assets (e.g. network globe) early.
 */
export function useHomeFoldPassed() {
  const [passedFold, setPassedFold] = useState(false)

  useEffect(() => {
    if (typeof window === 'undefined') return

    const main = document.getElementById('main-content')

    const update = () => {
      const scrollTop = main ? main.scrollTop : window.scrollY
      const foldHeight = main?.clientHeight ?? window.innerHeight
      if (hasPassedHomeFold(scrollTop, foldHeight)) {
        setPassedFold(true)
      }
    }

    update()

    const target = main ?? window
    target.addEventListener('scroll', update, { passive: true })
    return () => target.removeEventListener('scroll', update)
  }, [])

  return passedFold
}
