import { useEffect, useLayoutEffect, useRef } from 'react'
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

function scrollPricingHashTarget(
  hash: string,
  behavior: ScrollBehavior,
  resetMain: boolean,
) {
  if (!isPricingHashTarget(hash)) return

  resetPricingPageScrollContainers(resetMain)

  let attempts = 0
  const tryScroll = () => {
    const target = document.getElementById(hash)
    if (!target && attempts < MAX_HASH_SCROLL_ATTEMPTS) {
      attempts += 1
      requestAnimationFrame(tryScroll)
      return
    }

    if (target) {
      scrollToComparisonSection(hash, behavior)
    }
  }

  tryScroll()
}

/**
 * Scroll pricing hash targets inside `#main-content` instead of the document.
 * Resets shell scroll so the sticky header and footer layout stay intact.
 */
export function usePricingHashScroll() {
  const location = useLocation()
  const shouldResetMainRef = useRef(true)

  useLayoutEffect(() => {
    if (typeof window === 'undefined') return

    const hash = getPricingHash(location.hash)
    if (!isPricingHashTarget(hash)) return

    const previousRestoration = history.scrollRestoration
    history.scrollRestoration = 'manual'

    const resetMain = shouldResetMainRef.current
    shouldResetMainRef.current = false

    scrollPricingHashTarget(hash, 'auto', resetMain)

    return () => {
      history.scrollRestoration = previousRestoration
    }
  }, [location.hash, location.pathname])

  useEffect(() => {
    if (typeof window === 'undefined') return

    const root = document.querySelector('.root-container')
    const lockRootScroll = () => {
      if (root instanceof HTMLElement && root.scrollTop !== 0) {
        root.scrollTop = 0
      }
    }

    if (root instanceof HTMLElement) {
      root.addEventListener('scroll', lockRootScroll, { passive: true })
      lockRootScroll()
    }

    const onHashChange = () => {
      const nextHash = window.location.hash.slice(1)
      if (!isPricingHashTarget(nextHash)) return

      scrollPricingHashTarget(nextHash, 'smooth', false)
    }

    const onPricingHashClick = (event: MouseEvent) => {
      if (event.defaultPrevented) return

      const target = event.target
      if (!(target instanceof Element)) return

      const anchor = target.closest('a[href^="#"]')
      if (!anchor || !(anchor instanceof HTMLAnchorElement)) return

      const href = anchor.getAttribute('href')
      if (!href?.startsWith('#')) return

      const hash = href.slice(1)
      if (!isPricingHashTarget(hash)) return

      event.preventDefault()
      scrollPricingHashTarget(hash, 'smooth', false)
    }

    window.addEventListener('hashchange', onHashChange)
    document.addEventListener('click', onPricingHashClick, true)

    return () => {
      if (root instanceof HTMLElement) {
        root.removeEventListener('scroll', lockRootScroll)
      }
      window.removeEventListener('hashchange', onHashChange)
      document.removeEventListener('click', onPricingHashClick, true)
    }
  }, [])

  useEffect(() => {
    shouldResetMainRef.current = true
  }, [location.pathname])
}
