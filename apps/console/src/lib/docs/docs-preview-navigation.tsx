'use client'

import { createContext, useContext, type ReactNode } from 'react'
import type { DocsPreviewView } from '@/lib/docs/docs-preview-menu'

type DocsPreviewNavigationContextValue = {
  navigateToSlug: (slug: string, view?: DocsPreviewView) => void
}

const DocsPreviewNavigationContext =
  createContext<DocsPreviewNavigationContextValue | null>(null)

export function DocsPreviewNavigationProvider({
  navigateToSlug,
  children,
}: {
  navigateToSlug: (slug: string, view?: DocsPreviewView) => void
  children: ReactNode
}) {
  return (
    <DocsPreviewNavigationContext.Provider value={{ navigateToSlug }}>
      {children}
    </DocsPreviewNavigationContext.Provider>
  )
}

export function useDocsPreviewNavigation() {
  return useContext(DocsPreviewNavigationContext)
}
