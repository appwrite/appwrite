'use client'

import { useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { useLocation } from '@tanstack/react-router'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { DocsLeftNav } from '@/components/pages/docs/DocsLeftNav'
import { DocsProjectProvider } from '@/components/pages/docs/project-context/DocsProjectContext'
import {
  DocsSearchProvider,
  useDocsSearchContext,
} from '@/components/pages/docs/DocsSearchProvider'
import { DOCS_CONTAINER } from '@/lib/docs/docs-container'
import { shouldResetDocsScrollOnPathChange } from '@/lib/docs/docs-scroll'
import { isApiReferenceExplorerPath } from '@/lib/docs/references/is-api-reference-explorer-path'
import { ApiReferenceUiPrefsProvider } from '@/lib/docs/references/ApiReferenceUiPrefsProvider'
import { useMarketingSiteLayoutProvided } from '@/lib/marketing/marketing-site-layout-context'
import { cn } from '@/lib/utils'
import { resetPageSurfaceScroll } from '@/lib/layout/marketing-document-scroll'

type DocsPageShellProps = {
  children: ReactNode
}

function scrollDocsContentToTop() {
  if (typeof document === 'undefined') return
  resetPageSurfaceScroll('instant')
}

function DocsScrollToTop() {
  const { pathname } = useLocation()
  const previousNormalizedPathRef = useRef<string | null>(null)

  useLayoutEffect(() => {
    const { nextNormalizedPath, shouldScroll } =
      shouldResetDocsScrollOnPathChange(
        previousNormalizedPathRef.current,
        pathname,
      )
    previousNormalizedPathRef.current = nextNormalizedPath
    if (!shouldScroll) return
    // The persistent shell owns cross-page resets, even when an article remounts.
    // Run before article passive effects resolve a valid destination hash.
    scrollDocsContentToTop()
  }, [pathname])

  return null
}

function DocsPageShellLayout({ children }: DocsPageShellProps) {
  const nestedInMarketing = useMarketingSiteLayoutProvided()
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const docsSearch = useDocsSearchContext()
  const { pathname } = useLocation()
  const isReferenceExplorer = isApiReferenceExplorerPath(pathname)

  const article = (
    <>
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
    </>
  )

  if (nestedInMarketing) {
    return <ApiReferenceUiPrefsProvider>{article}</ApiReferenceUiPrefsProvider>
  }

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
      {article}
    </ConsoleLayout>
  )

  return <ApiReferenceUiPrefsProvider>{layout}</ApiReferenceUiPrefsProvider>
}

export function DocsPageShell({ children }: DocsPageShellProps) {
  const nestedInMarketing = useMarketingSiteLayoutProvided()
  const layout = (
    <DocsProjectProvider>
      <DocsPageShellLayout>{children}</DocsPageShellLayout>
    </DocsProjectProvider>
  )
  if (nestedInMarketing) return layout
  return <DocsSearchProvider>{layout}</DocsSearchProvider>
}
