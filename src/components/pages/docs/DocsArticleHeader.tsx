'use client'

import { useEffect, useRef, useState, type ReactNode } from 'react'
import type { DocsNavParent } from '@/lib/docs/types'
import {
  DOCS_PAGE_DESCRIPTION_CLASS,
  DOCS_PAGE_EYEBROW_CLASS,
  DOCS_PAGE_TITLE_CLASS,
} from '@/lib/docs/prose-typography'
import { cn, findScrollParent } from '@/lib/utils'
import { DocsRouteLink } from './DocsRouteLink'

type DocsArticleHeaderProps = {
  title: string
  description?: string
  readingTimeMinutes?: number
  parent?: DocsNavParent | null
  actions?: ReactNode
  className?: string
}

type StickyBounds = {
  top: number
  shellLeft: number
  shellWidth: number
  contentInsetLeft: number
  contentWidth: number
}

const DOCS_STICKY_TITLE_CLASS =
  'min-w-0 truncate font-aeonik-pro text-[15px] font-medium leading-tight text-foreground sm:text-[17px]'

export function DocsArticleHeader({
  title,
  description,
  readingTimeMinutes,
  parent,
  actions,
  className,
}: DocsArticleHeaderProps) {
  const sentinelRef = useRef<HTMLDivElement>(null)
  const [showStickyHeader, setShowStickyHeader] = useState(false)
  const [stickyBounds, setStickyBounds] = useState<StickyBounds | null>(null)

  useEffect(() => {
    setShowStickyHeader(false)
    setStickyBounds(null)

    const sentinel = sentinelRef.current
    if (!sentinel) return

    const updatePinned = () => {
      const currentSentinel = sentinelRef.current
      if (!currentSentinel) return

      const scrollRoot =
        findScrollParent(currentSentinel) ??
        document.getElementById('main-content')
      const article = currentSentinel.closest('article')
      const scrollContainer =
        scrollRoot instanceof HTMLElement ? scrollRoot : document.getElementById('main-content')

      const shellTop = scrollContainer?.getBoundingClientRect().top ?? 0
      const sentinelTop = currentSentinel.getBoundingClientRect().top
      const pinned = sentinelTop <= shellTop + 1

      setShowStickyHeader(pinned)

      if (pinned && article && scrollContainer) {
        const shellRect = scrollContainer.getBoundingClientRect()
        const articleRect = article.getBoundingClientRect()
        setStickyBounds({
          top: shellTop,
          shellLeft: shellRect.left,
          shellWidth: shellRect.width,
          contentInsetLeft: articleRect.left - shellRect.left,
          contentWidth: articleRect.width,
        })
      } else {
        setStickyBounds(null)
      }
    }

    const scrollRoot =
      findScrollParent(sentinel) ??
      document.getElementById('main-content')

    const observer = new IntersectionObserver(
      () => {
        updatePinned()
      },
      {
        root: scrollRoot,
        threshold: 0,
      },
    )

    observer.observe(sentinel)
    scrollRoot?.addEventListener('scroll', updatePinned, { passive: true })
    window.addEventListener('scroll', updatePinned, { passive: true })
    window.addEventListener('resize', updatePinned)
    updatePinned()

    return () => {
      observer.disconnect()
      scrollRoot?.removeEventListener('scroll', updatePinned)
      window.removeEventListener('scroll', updatePinned)
      window.removeEventListener('resize', updatePinned)
    }
  }, [title])

  return (
    <>
      {showStickyHeader && stickyBounds ? (
        <header
          className="pointer-events-none fixed z-30"
          style={{
            top: stickyBounds.top,
            left: stickyBounds.shellLeft,
            width: stickyBounds.shellWidth,
          }}
          aria-label="Article toolbar"
        >
          <div
            className="pointer-events-auto bg-background/95 py-3 backdrop-blur-sm supports-[backdrop-filter]:bg-background/80"
            style={{
              marginLeft: stickyBounds.contentInsetLeft,
              width: stickyBounds.contentWidth,
              maxWidth: stickyBounds.contentWidth,
            }}
          >
            <div className="flex items-center justify-between gap-4">
              <p className={DOCS_STICKY_TITLE_CLASS}>
                {title}
                <span className="text-[var(--brand-cta)]">_</span>
              </p>
              {actions ? (
                <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
                  {actions}
                </div>
              ) : null}
            </div>
          </div>
          <div
            aria-hidden
            className="pointer-events-none absolute inset-x-0 bottom-0 border-b border-border"
          />
        </header>
      ) : null}

      <header className={cn('mb-12', className)}>
        <div className="flex items-start justify-between gap-6">
          <div className="min-w-0 max-w-3xl">
            {parent ? (
              <p className={DOCS_PAGE_EYEBROW_CLASS}>
                <DocsRouteLink
                  href={parent.href}
                  className="text-muted-foreground transition-colors hover:text-foreground/85"
                >
                  {parent.label}
                </DocsRouteLink>
              </p>
            ) : null}

            <h1 className={cn(DOCS_PAGE_TITLE_CLASS, parent ? 'mt-3' : undefined)}>
              {title}
              <span className="text-[var(--brand-cta)]">_</span>
            </h1>
            <div ref={sentinelRef} className="h-px w-full" aria-hidden />

            {description ? (
              <p className={DOCS_PAGE_DESCRIPTION_CLASS}>{description}</p>
            ) : null}

            {readingTimeMinutes ? (
              <p className="mt-3 text-[12px] text-muted-foreground">
                {readingTimeMinutes} min read
              </p>
            ) : null}
          </div>

          {actions ? (
            <div className="hidden shrink-0 items-center gap-2 sm:flex">{actions}</div>
          ) : null}
        </div>

        {actions ? (
          <div className="mt-5 flex flex-wrap items-center gap-2 sm:hidden">{actions}</div>
        ) : null}

        <div className="mt-10 h-px w-full bg-border" aria-hidden />
      </header>
    </>
  )
}
