'use client'

import { useRef, type ReactNode } from 'react'
import type { DocsNavParent } from '@/lib/docs/types'
import {
  DOCS_PAGE_DESCRIPTION_CLASS,
  DOCS_PAGE_EYEBROW_CLASS,
  DOCS_PAGE_TITLE_CLASS,
} from '@/lib/docs/prose-typography'
import { ArticleStickyToolbar } from '@/components/global/shared/ArticleStickyToolbar'
import { useArticleStickyOverlay } from '@/lib/layout/use-article-sticky-overlay'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from './DocsRouteLink'

type DocsArticleHeaderProps = {
  title: string
  description?: string
  readingTimeMinutes?: number
  parent?: DocsNavParent | null
  actions?: ReactNode
  className?: string
}

export function DocsArticleHeader({
  title,
  description,
  readingTimeMinutes,
  parent,
  actions,
  className,
}: DocsArticleHeaderProps) {
  const sentinelRef = useRef<HTMLDivElement>(null)
  const { pinned, bounds } = useArticleStickyOverlay({
    sentinelRef,
    resetKey: title,
  })

  return (
    <>
      <ArticleStickyToolbar
        pinned={pinned}
        bounds={bounds}
        title={title}
        titleSuffix={<span className="text-[var(--brand-cta)]">_</span>}
        actions={actions}
      />

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
            <div className="hidden shrink-0 items-center gap-2 @[560px]:flex">
              {actions}
            </div>
          ) : null}
        </div>

        {actions ? (
          <div className="mt-5 flex flex-wrap items-center gap-2 @[560px]:hidden">
            {actions}
          </div>
        ) : null}

        <div className="mt-10 h-px w-full bg-border" aria-hidden />
      </header>
    </>
  )
}
