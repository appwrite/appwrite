'use client'

import type { ReactNode } from 'react'
import { DOCS_STICKY_TITLE_CLASS } from '@/lib/docs/prose-typography'
import { DOCS_SECTION_HEADER_CLASS } from '@/lib/docs/nav-styles'
import type { StickyOverlayBounds } from '@/lib/layout/sticky-overlay-bounds'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type ArticleStickyToolbarProps = {
  pinned: boolean
  bounds: StickyOverlayBounds | null
  title: string
  titleSuffix?: ReactNode
  actions?: ReactNode
  titleClassName?: string
}

export function ArticleStickyToolbar({
  pinned,
  bounds,
  title,
  titleSuffix,
  actions,
  titleClassName = DOCS_STICKY_TITLE_CLASS,
}: ArticleStickyToolbarProps) {
  const t = useT()
  if (!pinned || !bounds) return null

  const contentInsetInlineStart = bounds.contentLeft - bounds.shellLeft

  return (
    <header
      className="pointer-events-none fixed z-30 h-14"
      style={{
        top: bounds.top,
        left: bounds.shellLeft,
        width: bounds.shellWidth,
      }}
      aria-label={t('Article toolbar')}
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
          marginInlineStart: contentInsetInlineStart,
          width: bounds.contentWidth,
          maxWidth: bounds.contentWidth,
        }}
      >
        <div className="flex w-full min-w-0 items-center justify-between gap-4">
          <p className={cn(titleClassName, 'min-w-0 text-start')}>
            {t(title)}
            {titleSuffix}
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
  )
}
