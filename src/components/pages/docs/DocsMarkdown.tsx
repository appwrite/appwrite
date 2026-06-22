'use client'

import React, { useMemo } from 'react'
import {
  DOCS_PROSE_DETAIL_CLASSES,
  DOCS_PROSE_WRAPPER_CLASS,
} from '@/lib/docs/prose-typography'
import { ImagePreviewGalleryProvider } from '@/components/global/shared/ImagePreviewGallery'
import { cn } from '@/lib/utils'
import { renderDocsMarkdocReact } from './docs-markdoc-render'

type DocsMarkdownProps = {
  content: string
  compact?: boolean
}

export function DocsMarkdown({ content, compact = false }: DocsMarkdownProps) {
  const rendered = useMemo(
    () => renderDocsMarkdocReact(content, compact),
    [content, compact],
  )

  return (
    <ImagePreviewGalleryProvider>
      <div
        className={cn(
          DOCS_PROSE_WRAPPER_CLASS,
          ...DOCS_PROSE_DETAIL_CLASSES,
          compact && 'text-[14px] leading-[1.65] @[480px]:text-[15px]',
        )}
      >
        {rendered}
      </div>
    </ImagePreviewGalleryProvider>
  )
}
