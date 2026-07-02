import type { ReactNode } from 'react'
import { useMatches } from '@tanstack/react-router'
import { InitOrgPromoBanner } from '@/components/pages/organizations/$orgId/overview/_components/InitOrgPromoBanner'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/KeyboardShortcuts'
import { MarketingScrollToTop } from '@/lib/marketing/MarketingScrollToTop'
import { MarketingSiteLayoutProvider } from '@/lib/marketing/marketing-site-layout-context'
import { resolveMarketingRouteShellOptions } from '@/lib/marketing/marketing-route-shell'

type MarketingSiteLayoutProps = {
  children: ReactNode
}

export function MarketingSiteLayout({ children }: MarketingSiteLayoutProps) {
  const matches = useMatches()

  const shellOptions =
    resolveMarketingRouteShellOptions(matches) ?? {
      showFooter: true,
      expandedFooter: true,
    }

  const headerBanner =
    shellOptions.headerBanner === 'init-org-promo' ? (
      <InitOrgPromoBanner />
    ) : undefined

  return (
    <MarketingSiteLayoutProvider>
      <StandaloneCommandCenterScope context="account">
        <ConsoleLayout
          header={{
            marketingNav: true,
          }}
          headerBanner={headerBanner}
          showFooter={shellOptions.showFooter}
          footer={
            shellOptions.showFooter
              ? { expanded: shellOptions.expandedFooter }
              : undefined
          }
        >
          <MarketingScrollToTop />
          {children}
        </ConsoleLayout>
      </StandaloneCommandCenterScope>
    </MarketingSiteLayoutProvider>
  )
}
