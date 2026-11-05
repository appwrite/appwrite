'use client'

import { createContext, useContext } from 'react'
import type { DocsPreviewView } from '@/lib/docs/docs-preview-menu'

export type DocsPreviewOpenOptions = {
  view?: DocsPreviewView
}

export type DocsPreviewContextValue = {
  isOpen: boolean
  slug: string | null
  view: DocsPreviewView
  openDocsPreview: (slug: string, options?: DocsPreviewOpenOptions) => void
  closeDocsPreview: () => void
}

export const DocsPreviewContext = createContext<DocsPreviewContextValue | null>(
  null,
)

export function useDocsPreview() {
  const context = useContext(DocsPreviewContext)
  if (!context) {
    return {
      isOpen: false,
      slug: null,
      view: 'article' as const,
      openDocsPreview: () => {},
      closeDocsPreview: () => {},
    }
  }
  return context
}
