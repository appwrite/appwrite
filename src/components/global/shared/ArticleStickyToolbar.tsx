'use client'

import type { ReactNode } from 'react'
import { DOCS_STICKY_TITLE_CLASS } from '@/lib/docs/prose-typography'
import { DOCS_SECTION_HEADER_CLASS } from '@/lib/docs/nav-styles'
import type { StickyOverlayBounds } from '@/lib/layout/sticky-overlay-bounds'
import { cn } from '@/lib/utils'

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
  if (!pinned || !bounds) return null

  return (
    <>
      <div
        aria-hidden
        className="pointer-events-none fixed z-30 h-14 border-b border-border"
        style={{
          top: bounds.top,
          left: bounds.shellLeft,
          width: bounds.shellWidth,
        }}
      />
      <header
        className="pointer-events-none fixed z-30"
        style={{
          top: bounds.top,
          left: bounds.contentLeft,
          width: bounds.contentWidth,
        }}
        aria-label="Article toolbar"
      >
        <div
          className={cn(
            DOCS_SECTION_HEADER_CLASS,
            'pointer-events-auto border-b-0 bg-background/95 backdrop-blur-sm supports-[backdrop-filter]:bg-background/80',
          )}
        >
          <div className="flex w-full min-w-0 items-center justify-between gap-4">
            <p className={cn(titleClassName, 'min-w-0 text-start')}>
              {title}
              {titleSuffix}
            </p>
            {actions ? (
              <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
                {actions}
              </div>
            ) : null}
          </div>
        </div>
      </header>
    </>
  )
}
