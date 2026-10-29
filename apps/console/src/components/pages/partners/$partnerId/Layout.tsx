import { Outlet, useMatches, useSearch } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/StandaloneCommandCenterScope'
import { OrganizationBillingHeaderBanners } from '@/components/global/shared/OrganizationBillingHeaderBanners'
import { PartnerWorkspace } from '@/components/pages/partners/$partnerId/Workspace'
import {
  isPartnerClientSection,
  type PartnerClientPageSearch,
} from '@/lib/partners'
import { useT } from '@/lib/i18n/translate'

const WORKSPACE_INDEX_ROUTE_ID = '/_public/partners/$partnerId/'
const CLIENTS_LAYOUT_ROUTE_ID = '/_public/partners/$partnerId/clients'
const CLIENTS_INDEX_ROUTE_ID = '/_public/partners/$partnerId/clients/'
const CLIENT_DETAIL_ROUTE_ID = '/_public/partners/$partnerId/clients/$clientId'

function isPartnerWorkspaceShellRoute(routeId: string) {
  return (
    routeId === WORKSPACE_INDEX_ROUTE_ID ||
    routeId === CLIENTS_LAYOUT_ROUTE_ID ||
    routeId === CLIENTS_INDEX_ROUTE_ID ||
    routeId === CLIENT_DETAIL_ROUTE_ID
  )
}

function PartnerWorkspaceShell({ partnerId }: { partnerId: string }) {
  const matches = useMatches()
  const search = useSearch({ strict: false }) as PartnerClientPageSearch
  const clientMatch = matches.find(
    (match) => match.routeId === CLIENT_DETAIL_ROUTE_ID,
  )
  const isClients = matches.some(
    (match) =>
      match.routeId === CLIENTS_LAYOUT_ROUTE_ID ||
      match.routeId === CLIENTS_INDEX_ROUTE_ID ||
      match.routeId === CLIENT_DETAIL_ROUTE_ID,
  )
  const clientId = (
    clientMatch?.params as { clientId?: string } | undefined
  )?.clientId
  const section = isPartnerClientSection(search.section)
    ? search.section
    : undefined

  return (
    <PartnerWorkspace
      partnerId={partnerId}
      view={isClients ? 'clients' : 'workspace'}
      clientId={clientId}
      section={section}
    />
  )
}

export function PartnerLayout({ partnerId }: { partnerId: string }) {
  const t = useT()
  const matches = useMatches()
  const isWorkspaceShell = matches.some((match) =>
    isPartnerWorkspaceShellRoute(match.routeId),
  )

  return (
    <RequireAuth>
      <StandaloneCommandCenterScope context="org" orgId={partnerId}>
        <ConsoleLayout
          showFooter
          footer={{ separator: false }}
          fixedLayout
          containerClassName="org-layout-container"
          header={{
            marketingNav: true,
            headerTitleSuffix: t('Partners'),
            centerSearch: true,
            centerSearchPlaceholder: t('Search...'),
          }}
          headerBanner={
            <OrganizationBillingHeaderBanners organizationId={partnerId} />
          }
        >
          {isWorkspaceShell ? (
            <PartnerWorkspaceShell partnerId={partnerId} />
          ) : (
            <Outlet />
          )}
        </ConsoleLayout>
      </StandaloneCommandCenterScope>
    </RequireAuth>
  )
}
