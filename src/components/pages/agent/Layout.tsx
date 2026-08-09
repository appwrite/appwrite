import { Outlet, useParams } from '@tanstack/react-router'
import { StandaloneCommandCenterScope } from '@/components/global/providers/KeyboardShortcuts'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { OrganizationBillingHeaderBanners } from '@/components/global/shared/OrganizationBillingHeaderBanners'

/** Org-scoped agent surface with console header; child views own the chat chrome. */
export function Layout() {
  const { orgId } = useParams({ strict: false })

  return (
    <StandaloneCommandCenterScope context="org" orgId={orgId}>
      <ConsoleLayout
        headerBanner={
          orgId ? (
            <OrganizationBillingHeaderBanners organizationId={orgId} />
          ) : null
        }
        showFooter={false}
        fixedLayout
        containerClassName="org-layout-container"
      >
        <div className="flex h-full min-h-0 flex-1 flex-col overflow-hidden bg-background">
          <Outlet />
        </div>
      </ConsoleLayout>
    </StandaloneCommandCenterScope>
  )
}
