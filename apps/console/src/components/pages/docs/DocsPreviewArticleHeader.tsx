'use client'

import type { RefObject, ReactNode } from 'react'
import type { DocsTocItem } from '@/lib/docs/types'
import { docsPreviewPrimaryTitleClass } from '@/lib/docs/docs-container'
import { cn } from '@/lib/utils'
import { DocsPageHeaderActions } from './DocsPageHeaderActions'
import { DocsPreviewInlineToc } from './DocsPreviewInlineToc'

const PREVIEW_DESCRIPTION_CLASS =
  'mt-2.5 max-w-2xl text-[13px] leading-[1.6] text-muted-foreground @[480px]:text-[14px]'

type DocsPreviewArticleHeaderProps = {
  title: string
  description?: string
  readingTimeMinutes?: number
  slug: string
  toc?: DocsTocItem[]
  scrollContainerRef?: RefObject<HTMLElement | null>
  actions?: ReactNode
  showActions?: boolean
  className?: string
}

export function DocsPreviewArticleHeader({
  title,
  description,
  readingTimeMinutes,
  slug,
  toc = [],
  scrollContainerRef,
  actions,
  showActions = true,
  className,
}: DocsPreviewArticleHeaderProps) {
  const headerActions =
    actions ??
    (showActions ? (
      <DocsPageHeaderActions
        slug={slug}
        buttonClassName="h-8 text-[12px] @[480px]:h-9 @[480px]:text-[13px]"
      />
    ) : null)

  return (
    <header className={cn('mb-6', className)}>
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <h1 className={docsPreviewPrimaryTitleClass}>
            {title}
            <span className="text-[var(--brand-cta)]">_</span>
          </h1>
          {description ? (
            <p className={PREVIEW_DESCRIPTION_CLASS}>{description}</p>
          ) : null}
          {readingTimeMinutes ? (
            <p className="mt-2.5 text-[12px] text-muted-foreground">
              {readingTimeMinutes} min read
            </p>
          ) : null}
        </div>

        {headerActions ? (
          <div className="hidden shrink-0 items-center gap-2 @[560px]:flex">
            {headerActions}
          </div>
        ) : null}
      </div>

      {headerActions ? (
        <div className="mt-4 flex flex-wrap items-center gap-2 @[560px]:hidden">
          {headerActions}
        </div>
      ) : null}

      <DocsPreviewInlineToc items={toc} scrollContainerRef={scrollContainerRef} />

      <div className="mt-6 h-px w-full bg-border" aria-hidden />
    </header>
  )
}
