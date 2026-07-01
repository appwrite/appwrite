'use client'

import { useEffect, useState, type RefObject } from 'react'
import {
  measureStickyOverlayBounds,
  type StickyOverlayBounds,
} from '@/lib/layout/sticky-overlay-bounds'
import { findScrollParent } from '@/lib/utils'

type UseArticleStickyOverlayOptions = {
  sentinelRef: RefObject<HTMLElement | null>
  /** Measured for horizontal alignment; falls back to closest `article`. */
  contentAnchorRef?: RefObject<HTMLElement | null>
  resetKey?: unknown
}

export function useArticleStickyOverlay({
  sentinelRef,
  contentAnchorRef,
  resetKey,
}: UseArticleStickyOverlayOptions) {
  const [pinned, setPinned] = useState(false)
  const [bounds, setBounds] = useState<StickyOverlayBounds | null>(null)

  useEffect(() => {
    setPinned(false)
    setBounds(null)

    const sentinel = sentinelRef.current
    if (!sentinel) return

    const updateOverlay = () => {
      const currentSentinel = sentinelRef.current
      if (!currentSentinel) return

      const scrollRoot =
        findScrollParent(currentSentinel) ??
        document.getElementById('main-content')
      const contentAnchor =
        contentAnchorRef?.current ?? currentSentinel.closest('article')
      const scrollContainer =
        scrollRoot instanceof HTMLElement ? scrollRoot : document.getElementById('main-content')

      const shellTop = scrollContainer?.getBoundingClientRect().top ?? 0
      const sentinelTop = currentSentinel.getBoundingClientRect().top
      const isPinned = sentinelTop <= shellTop + 1

      setPinned(isPinned)

      if (isPinned && contentAnchor && scrollContainer) {
        const shellRect = scrollContainer.getBoundingClientRect()
        const contentRect = contentAnchor.getBoundingClientRect()
        setBounds(measureStickyOverlayBounds(shellRect, contentRect, shellTop))
      } else {
        setBounds(null)
      }
    }

    const scrollRoot =
      findScrollParent(sentinel) ??
      document.getElementById('main-content')

    const observer = new IntersectionObserver(updateOverlay, {
      root: scrollRoot,
      threshold: 0,
    })

    observer.observe(sentinel)
    scrollRoot?.addEventListener('scroll', updateOverlay, { passive: true })
    window.addEventListener('scroll', updateOverlay, { passive: true })
    window.addEventListener('resize', updateOverlay)
    const dirObserver = new MutationObserver(updateOverlay)
    dirObserver.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['dir'],
    })
    updateOverlay()

    return () => {
      observer.disconnect()
      dirObserver.disconnect()
      scrollRoot?.removeEventListener('scroll', updateOverlay)
      window.removeEventListener('scroll', updateOverlay)
      window.removeEventListener('resize', updateOverlay)
    }
  }, [resetKey])

  return { pinned, bounds }
}
