'use client'

import { useEffect, useRef } from 'react'
import { useLocation } from '@tanstack/react-router'
import { resetPageSurfaceScroll } from '@/lib/layout/marketing-document-scroll'

export function scrollMarketingMainToTop(behavior: ScrollBehavior = 'auto') {
  if (typeof document === 'undefined') return
  resetPageSurfaceScroll(behavior)
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
