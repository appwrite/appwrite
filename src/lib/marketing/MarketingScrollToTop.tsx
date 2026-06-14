'use client'

import { useEffect, useRef } from 'react'
import { useLocation } from '@tanstack/react-router'
import { resetConsoleShellDocumentScroll } from '@/lib/utils'

export function scrollMarketingMainToTop(behavior: ScrollBehavior = 'auto') {
  if (typeof document === 'undefined') return

  const main = document.getElementById('main-content')
  if (main) {
    main.scrollTo({ top: 0, behavior })
    return
  }

  resetConsoleShellDocumentScroll()
}

export function MarketingScrollToTop() {
  const { pathname } = useLocation()
  const previousPathnameRef = useRef(pathname)

  useEffect(() => {
    if (previousPathnameRef.current === pathname) return
    previousPathnameRef.current = pathname
    scrollMarketingMainToTop()
  }, [pathname])

  return null
}
