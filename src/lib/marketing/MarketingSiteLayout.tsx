import { useEffect, useState, type ReactNode } from 'react'
import { useMatches, useRouterState } from '@tanstack/react-router'
import { InitOrgPromoBanner } from '@/components/pages/organizations/$orgId/overview/_components/InitOrgPromoBanner'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/StandaloneCommandCenterScope'
import { DocsLeftNav } from '@/components/pages/docs/DocsLeftNav'
import {
  DocsSearchProvider,
  useDocsSearchContext,
} from '@/components/pages/docs/DocsSearchProvider'
import { MarketingScrollToTop } from '@/lib/marketing/MarketingScrollToTop'
import { MarketingSiteLayoutProvider } from '@/lib/marketing/marketing-site-layout-context'
import { resolveMarketingRouteShellOptions } from '@/lib/marketing/marketing-route-shell'
import { isApiReferenceExplorerPath } from '@/lib/docs/references/is-api-reference-explorer-path'
import { setMarketingDocumentScroll } from '@/lib/layout/marketing-document-scroll'

type MarketingSiteLayoutProps = {
  children: ReactNode
}

function isDocsPath(pathname: string): boolean {
  return pathname === '/docs' || pathname.startsWith('/docs/')
}

function MarketingConsoleShell({ children }: MarketingSiteLayoutProps) {
  const matches = useMatches()
  const pathname = useRouterState({
    select: (s) => s.resolvedLocation?.pathname ?? s.location.pathname,
  })
  const isDocs = isDocsPath(pathname)
  const isReferenceExplorer = isDocs && isApiReferenceExplorerPath(pathname)
  const docsSearch = useDocsSearchContext()
  const [sidebarOpen, setSidebarOpen] = useState(false)

  // Console keeps a nested scroller (`#main-content`) because the shell is
  // `position: fixed`. Plausible only measures window/document scroll, so that
  // nested scroller reported 100% depth on every marketing visit. Explorer
  // stays nested so the API docs pane can manage its own overflow.
  useEffect(() => {
    if (isReferenceExplorer) {
      setMarketingDocumentScroll(false)
      return
    }
    setMarketingDocumentScroll(true)
    return () => setMarketingDocumentScroll(false)
  }, [isReferenceExplorer])

  const shellOptions =
    resolveMarketingRouteShellOptions(matches) ?? {
      showFooter: true,
      expandedFooter: true,
    }

  const headerBanner =
    !isDocs && shellOptions.headerBanner === 'init-org-promo' ? (
      <InitOrgPromoBanner />
    ) : undefined

  return (
    <StandaloneCommandCenterScope context="account">
      <ConsoleLayout
        header={
          isDocs
            ? {
                marketingNav: true,
                headerTitleSuffix: 'Docs',
                centerSearch: true,
                centerSearchPlaceholder: 'Search documentation...',
                onCommandCenterOpen: docsSearch?.openDocsSearch,
              }
            : {
                marketingNav: true,
              }
        }
        headerBanner={headerBanner}
        leftSidebar={
          isDocs
            ? {
                mobileOpen: sidebarOpen,
                onMobileClose: () => setSidebarOpen(false),
                onMenuClick: () => setSidebarOpen(true),
                content: (
                  <DocsLeftNav
                    mobileOpen={sidebarOpen}
                    onMobileClose={() => setSidebarOpen(false)}
                  />
                ),
              }
            : undefined
        }
        fixedLayout={isReferenceExplorer}
        showFooter={isDocs ? !isReferenceExplorer : shellOptions.showFooter}
        footer={{
          expanded: isDocs ? false : shellOptions.expandedFooter,
        }}
      >
        <MarketingScrollToTop />
        {children}
      </ConsoleLayout>
    </StandaloneCommandCenterScope>
  )
}

export function MarketingSiteLayout({ children }: MarketingSiteLayoutProps) {
  return (
    <MarketingSiteLayoutProvider>
      <DocsSearchProvider>
        <MarketingConsoleShell>{children}</MarketingConsoleShell>
      </DocsSearchProvider>
    </MarketingSiteLayoutProvider>
  )
}
