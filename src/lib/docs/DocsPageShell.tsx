'use client'

import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useLocation } from '@tanstack/react-router'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { DocsLeftNav } from '@/components/pages/docs/DocsLeftNav'
import {
  DocsSearchProvider,
  useDocsSearchContext,
} from '@/components/pages/docs/DocsSearchProvider'
import { DOCS_CONTAINER } from '@/lib/docs/docs-container'
import { isApiReferenceExplorerPath } from '@/lib/docs/references/is-api-reference-explorer-path'
import { ApiReferenceUiPrefsProvider } from '@/lib/docs/references/ApiReferenceUiPrefsProvider'
import { cn, resetConsoleShellDocumentScroll } from '@/lib/utils'

type DocsPageShellProps = {
  children: ReactNode
}

function scrollDocsContentToTop() {
  if (typeof document === 'undefined') return

  const main = document.getElementById('main-content')
  if (main) {
    main.scrollTo({ top: 0, behavior: 'auto' })
    return
  }

  resetConsoleShellDocumentScroll()
}

function DocsScrollToTop() {
  const { pathname } = useLocation()
  const previousPathnameRef = useRef(pathname)

  useEffect(() => {
    if (previousPathnameRef.current === pathname) return
    previousPathnameRef.current = pathname
    scrollDocsContentToTop()
  }, [pathname])

  return null
}

function DocsPageShellLayout({ children }: DocsPageShellProps) {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const docsSearch = useDocsSearchContext()
  const { pathname } = useLocation()
  const isReferenceExplorer = isApiReferenceExplorerPath(pathname)
  const isReferencesSection = pathname.startsWith('/docs/references')

  const layout = (
    <ConsoleLayout
      header={{
        marketingNav: true,
        headerTitleSuffix: 'Docs',
        centerSearch: true,
        centerSearchPlaceholder: 'Search documentation...',
        onCommandCenterOpen: docsSearch?.openDocsSearch,
      }}
      leftSidebar={{
        mobileOpen: sidebarOpen,
        onMobileClose: () => setSidebarOpen(false),
        onMenuClick: () => setSidebarOpen(true),
        content: (
          <DocsLeftNav
            mobileOpen={sidebarOpen}
            onMobileClose={() => setSidebarOpen(false)}
          />
        ),
      }}
      fixedLayout={isReferenceExplorer}
      showFooter={!isReferenceExplorer}
      footer={{ expanded: false }}
    >
      <DocsScrollToTop />
      <div
        className={cn(
          isReferenceExplorer
            ? 'flex h-full min-h-0 w-full min-w-0 flex-col'
            : cn(DOCS_CONTAINER, 'min-w-0 w-full'),
        )}
      >
        {children}
      </div>
    </ConsoleLayout>
  )

  return isReferencesSection ? (
    <ApiReferenceUiPrefsProvider>{layout}</ApiReferenceUiPrefsProvider>
  ) : (
    layout
  )
}

export function DocsPageShell({ children }: DocsPageShellProps) {
  return (
    <DocsSearchProvider>
      <DocsPageShellLayout>{children}</DocsPageShellLayout>
    </DocsSearchProvider>
  )
}
