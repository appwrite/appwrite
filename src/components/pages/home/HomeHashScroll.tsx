'use client'

import { useEffect, useLayoutEffect, useRef } from 'react'
import { useLocation } from '@tanstack/react-router'
import {
  isHomeHashTarget,
  scrollToMarketingSection,
} from '@/lib/marketing/section-scroll'
import { resetConsoleShellDocumentScroll } from '@/lib/utils'

const MAX_HASH_SCROLL_ATTEMPTS = 24

function getHomeHash(locationHash?: string): string {
  const fromRouter = locationHash?.replace(/^#/, '') ?? ''
  if (fromRouter) return fromRouter
  return window.location.hash.slice(1)
}

function scrollHomeHashTarget(
  hash: string,
  behavior: ScrollBehavior,
  resetMain: boolean,
) {
  if (!isHomeHashTarget(hash)) return

  resetConsoleShellDocumentScroll()

  if (resetMain) {
    const main = document.getElementById('main-content')
    if (main) main.scrollTop = 0
  }

  let attempts = 0
  const tryScroll = () => {
    const target = document.getElementById(hash)
    if (!target && attempts < MAX_HASH_SCROLL_ATTEMPTS) {
      attempts += 1
      requestAnimationFrame(tryScroll)
      return
    }

    if (target) {
      scrollToMarketingSection(hash, behavior)
    }
  }

  tryScroll()
}

/** Scroll `#scale` inside `#main-content` so the sticky header stays visible. */
export function HomeHashScroll() {
  const location = useLocation()
  const shouldResetMainRef = useRef(true)

  useLayoutEffect(() => {
    if (typeof window === 'undefined') return

    const hash = getHomeHash(location.hash)
    if (!isHomeHashTarget(hash)) return

    const previousRestoration = history.scrollRestoration
    history.scrollRestoration = 'manual'

    const resetMain = shouldResetMainRef.current
    shouldResetMainRef.current = false

    scrollHomeHashTarget(hash, 'auto', resetMain)

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
      if (!isHomeHashTarget(nextHash)) return

      scrollHomeHashTarget(nextHash, 'smooth', false)
    }

    const onHomeHashClick = (event: MouseEvent) => {
      if (event.defaultPrevented) return

      const target = event.target
      if (!(target instanceof Element)) return

      const anchor = target.closest('a[href^="#"]')
      if (!anchor || !(anchor instanceof HTMLAnchorElement)) return

      const href = anchor.getAttribute('href')
      if (!href?.startsWith('#')) return

      const hash = href.slice(1)
      if (!isHomeHashTarget(hash)) return

      event.preventDefault()
      scrollHomeHashTarget(hash, 'smooth', false)
    }

    window.addEventListener('hashchange', onHashChange)
    document.addEventListener('click', onHomeHashClick, true)

    return () => {
      if (root instanceof HTMLElement) {
        root.removeEventListener('scroll', lockRootScroll)
      }
      window.removeEventListener('hashchange', onHashChange)
      document.removeEventListener('click', onHomeHashClick, true)
    }
  }, [])

  useEffect(() => {
    shouldResetMainRef.current = true
  }, [location.pathname])

  return null
}
