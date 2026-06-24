'use client'

import { Link } from '@tanstack/react-router'
import { useEffect, useRef, useState } from 'react'
import { formatDate } from '@/lib/date-utils'
import type { BlogAuthor } from '@/lib/blog/types'
import {
  BLOG_PAGE_DESCRIPTION_CLASS,
  BLOG_PAGE_TITLE_CLASS,
  BLOG_STICKY_TITLE_CLASS,
} from '@/lib/blog/prose-typography'
import { DOCS_SECTION_HEADER_CLASS } from '@/lib/docs/nav-styles'
import { cn, findScrollParent } from '@/lib/utils'
import { BlogPostShareActions } from './_components/BlogPostShareActions'
import { BlogAvatar } from './BlogCoverPlaceholder'

type BlogArticleHeaderProps = {
  slug: string
  title: string
  description: string
  authors: BlogAuthor[]
  date: string
  timeToRead: number
  lastUpdated: string
}

type StickyBounds = {
  top: number
  shellLeft: number
  shellWidth: number
  contentInsetLeft: number
  contentWidth: number
}

const BLOG_TITLE_SUFFIX = <span className="text-[var(--brand-cta)]">_</span>

export function BlogArticleHeader({
  slug,
  title,
  description,
  authors,
  date,
  timeToRead,
  lastUpdated,
}: BlogArticleHeaderProps) {
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

  const shareActions = <BlogPostShareActions slug={slug} title={title} />

  return (
    <>
      {showStickyHeader && stickyBounds ? (
        <header
          className="pointer-events-none fixed z-30 h-14"
          style={{
            top: stickyBounds.top,
            left: stickyBounds.shellLeft,
            width: stickyBounds.shellWidth,
          }}
          aria-label="Article toolbar"
        >
          <div
            aria-hidden
            className="absolute inset-0 bg-background/95 backdrop-blur-sm supports-[backdrop-filter]:bg-background/80"
          />
          <div
            className={cn(
              DOCS_SECTION_HEADER_CLASS,
              'relative pointer-events-auto border-b-0 bg-transparent',
            )}
            style={{
              marginLeft: stickyBounds.contentInsetLeft,
              width: stickyBounds.contentWidth,
              maxWidth: stickyBounds.contentWidth,
            }}
          >
            <div className="flex w-full items-center justify-between gap-4">
              <p className={BLOG_STICKY_TITLE_CLASS}>
                {title}
                {BLOG_TITLE_SUFFIX}
              </p>
              <div className="flex shrink-0 items-center justify-end">{shareActions}</div>
            </div>
          </div>
          <div
            aria-hidden
            className="pointer-events-none absolute inset-x-0 bottom-0 border-b border-border"
          />
        </header>
      ) : null}

      <header className="border-b border-border py-4">
        <div className="min-w-0">
          <h1 className={BLOG_PAGE_TITLE_CLASS}>
            {title}
            {BLOG_TITLE_SUFFIX}
          </h1>
          <div ref={sentinelRef} className="h-px w-full" aria-hidden />

          <p className={BLOG_PAGE_DESCRIPTION_CLASS}>{description}</p>

          <div className="mt-6 flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-4">
              {authors.map((author) => (
                <Link
                  key={author.slug}
                  to="/blog/author/$author"
                  params={{ author: author.slug }}
                  className="inline-flex items-center gap-2"
                >
                  <BlogAvatar name={author.name} avatar={author.avatar} />
                  <div className="min-w-0">
                    <p className="text-[13px] font-medium text-foreground">{author.name}</p>
                    {author.role ? (
                      <p className="text-[12px] text-muted-foreground">{author.role}</p>
                    ) : null}
                  </div>
                </Link>
              ))}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-4">
              <div className="flex flex-wrap items-center gap-3 text-[11px] uppercase tracking-[0.16em] text-muted-foreground">
                <time dateTime={date}>{formatDate(date)}</time>
                {timeToRead > 0 ? <span>{timeToRead} min read</span> : null}
                {lastUpdated !== date ? <span>Updated {formatDate(lastUpdated)}</span> : null}
              </div>
              {shareActions}
            </div>
          </div>
        </div>
      </header>
    </>
  )
}
