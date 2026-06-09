import { useEffect } from 'react'
import { useLocation } from '@tanstack/react-router'
import {
  isPricingHashTarget,
  resetPricingPageScrollContainers,
  scrollToComparisonSection,
} from '@/lib/pricing/comparison-scroll'

const MAX_HASH_SCROLL_ATTEMPTS = 24

function getPricingHash(locationHash?: string): string {
  const fromRouter = locationHash?.replace(/^#/, '') ?? ''
  if (fromRouter) return fromRouter
  return window.location.hash.slice(1)
}

/**
 * Scroll pricing hash targets inside `#main-content` instead of the document.
 * Resets window scroll on load so the sticky header and footer layout stay intact.
 */
export function usePricingHashScroll() {
  const location = useLocation()

  useEffect(() => {
    if (typeof window === 'undefined') return

    const hash = getPricingHash(location.hash)
    if (!isPricingHashTarget(hash)) return

    const previousRestoration = history.scrollRestoration
    history.scrollRestoration = 'manual'

    resetPricingPageScrollContainers(true)

    let attempts = 0
    const tryScroll = () => {
      const target = document.getElementById(hash)
      if (!target && attempts < MAX_HASH_SCROLL_ATTEMPTS) {
        attempts += 1
        requestAnimationFrame(tryScroll)
        return
      }

      if (target) {
        scrollToComparisonSection(hash, 'auto')
      }
    }

    tryScroll()

    const onHashChange = () => {
      const nextHash = window.location.hash.slice(1)
      if (!isPricingHashTarget(nextHash)) return

      resetPricingPageScrollContainers(false)
      scrollToComparisonSection(nextHash, 'smooth')
    }

    window.addEventListener('hashchange', onHashChange)

    return () => {
      history.scrollRestoration = previousRestoration
      window.removeEventListener('hashchange', onHashChange)
    }
  }, [location.hash, location.pathname])
}
