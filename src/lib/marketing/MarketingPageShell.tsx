import type { ReactNode } from 'react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/KeyboardShortcuts'
import { MarketingScrollToTop } from '@/lib/marketing/MarketingScrollToTop'

type MarketingPageShellProps = {
  children: ReactNode
}

export function MarketingPageShell({ children }: MarketingPageShellProps) {
  return (
    <StandaloneCommandCenterScope context="account">
      <ConsoleLayout
        header={{
          marketingNav: true,
        }}
        showFooter
        footer={{ expanded: true }}
      >
        <MarketingScrollToTop />
        {children}
      </ConsoleLayout>
    </StandaloneCommandCenterScope>
  )
}
