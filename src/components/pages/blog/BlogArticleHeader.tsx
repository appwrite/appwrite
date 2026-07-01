'use client'

import { Link } from '@tanstack/react-router'
import { useRef } from 'react'
import { formatDate } from '@/lib/date-utils'
import type { BlogAuthor } from '@/lib/blog/types'
import {
  BLOG_PAGE_DESCRIPTION_CLASS,
  BLOG_PAGE_TITLE_CLASS,
  BLOG_STICKY_TITLE_CLASS,
} from '@/lib/blog/prose-typography'
import { ArticleStickyToolbar } from '@/components/global/shared/ArticleStickyToolbar'
import { useArticleStickyOverlay } from '@/lib/layout/use-article-sticky-overlay'
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
  const contentAnchorRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)
  const { pinned, bounds } = useArticleStickyOverlay({
    sentinelRef,
    contentAnchorRef,
    resetKey: title,
  })

  const shareActions = <BlogPostShareActions slug={slug} title={title} />

  return (
    <>
      <ArticleStickyToolbar
        pinned={pinned}
        bounds={bounds}
        title={title}
        titleSuffix={BLOG_TITLE_SUFFIX}
        titleClassName={BLOG_STICKY_TITLE_CLASS}
        actions={shareActions}
      />

      <header className="border-b border-border py-4">
        <div ref={contentAnchorRef} className="min-w-0">
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
