'use client'

import { createContext, useContext, type ReactNode } from 'react'

type DocsPreviewNavigationContextValue = {
  navigateToSlug: (slug: string) => void
}

const DocsPreviewNavigationContext =
  createContext<DocsPreviewNavigationContextValue | null>(null)

export function DocsPreviewNavigationProvider({
  navigateToSlug,
  children,
}: {
  navigateToSlug: (slug: string) => void
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
