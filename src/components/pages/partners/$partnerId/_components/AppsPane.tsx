import { useState } from 'react'
import { useIsFetching, useQueryClient } from '@tanstack/react-query'
import { Import, Upload, type LucideIcon } from 'lucide-react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canShowOrgMarketplaceTab } from '@/lib/console-access-checks'
import { Button } from '@/components/ui/button'
import { View as OrgAppsView } from '@/components/pages/organizations/$orgId/apps/View'
import { TransferApp } from '@/components/pages/partners/$partnerId/_components/HandoffResource'
import { PartnerPaneHeaderActionButton } from '@/components/pages/partners/$partnerId/_components/DomainsPane'
import {
  PartnerListPaneHeader,
  usePartnerProjectsFilterState,
} from '@/components/pages/partners/$partnerId/_components/ProjectsPaneHeader'
import { useOrganizationScopes } from '@/lib/react-query/hooks'
import { appsFilterColumns } from '@/lib/table-filters'
import { useT } from '@/lib/i18n/translate'

export function ClientAppsPane({
  partnerId,
  organizationId,
  variant = 'client',
}: {
  partnerId: string
  organizationId: string
  variant?: 'workspace' | 'client'
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const [createOpen, setCreateOpen] = useState(false)
  const [handoffOpen, setHandoffOpen] = useState(false)
  const [handoffAppId, setHandoffAppId] = useState<string | undefined>()
  const [searchValue, setSearchValue] = useState('')
  const {
    filtersOpen,
    setFiltersOpen,
    filterMap,
    filterQueries,
    hasFilters,
    applyFilter,
    removeFilter,
    clearAll,
    applyQuery,
  } = usePartnerProjectsFilterState(() => undefined)
  const search = searchValue.trim() || undefined
  const listHasFilters = Boolean(search) || hasFilters
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(organizationId)
  const canManageApps = canShowOrgMarketplaceTab(access, features)
  const permissionTooltip = t("You don't have permission to manage apps.")
  const createDisabledTooltip = canManageApps ? undefined : permissionTooltip
  const isReloading =
    useIsFetching({ queryKey: ['apps', 'organization', organizationId] }) > 0
  const handoffTargetClientId =
    organizationId === partnerId ? undefined : organizationId
  const HandoffIcon: LucideIcon = variant === 'workspace' ? Upload : Import

  const handleReload = () => {
    void queryClient.refetchQueries({
      queryKey: ['apps', 'organization', organizationId],
    })
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      <PartnerListPaneHeader
        title={t('Apps')}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        searchPlaceholder={t('Search apps...')}
        columns={appsFilterColumns}
        filterMap={filterMap}
        filtersOpen={filtersOpen}
        onFiltersOpenChange={setFiltersOpen}
        onRemoveFilter={removeFilter}
        onClearAll={clearAll}
        onApplyFilter={applyFilter}
        onApplyQuery={applyQuery}
        resourceLabel={t('apps')}
        filterScope="partners.apps"
        teamId={organizationId}
        onReload={handleReload}
        isReloading={isReloading}
        actions={
          <PartnerPaneHeaderActionButton
            disabled={!canManageApps}
            tooltip={createDisabledTooltip}
            onClick={() => {
              setHandoffAppId(undefined)
              setHandoffOpen(true)
            }}
            analyticsAction="handoff-app"
          >
            <HandoffIcon className="h-3.5 w-3.5" />
            {t('Handoff app')}
          </PartnerPaneHeaderActionButton>
        }
        primaryAction={
          <PartnerPaneHeaderActionButton
            disabled={!canManageApps}
            tooltip={createDisabledTooltip}
            onClick={() => setCreateOpen(true)}
            analyticsAction="create-marketplace-app"
            variant="brandCta"
          >
            {t('Create app')}
          </PartnerPaneHeaderActionButton>
        }
      />
      <OrgAppsView
        organizationId={organizationId}
        openAppsInNewTab
        hideChrome
        embedded
        createOpen={createOpen}
        onCreateOpenChange={setCreateOpen}
        search={search}
        filterQueries={filterQueries}
        hasFilters={listHasFilters}
        onHandoff={
          canManageApps
            ? (appId) => {
                setHandoffAppId(appId)
                setHandoffOpen(true)
              }
            : undefined
        }
        handoffIcon={HandoffIcon}
        showContextMenus
        emptyAction={
          !listHasFilters && canManageApps ? (
            <div className="flex items-center gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    setHandoffAppId(undefined)
                    setHandoffOpen(true)
                  }}
                >
                  <HandoffIcon className="h-3.5 w-3.5" />
                  {t('Handoff app')}
                </Button>
              <Button
                size="sm"
                variant="brandCta"
                onClick={() => setCreateOpen(true)}
              >
                {t('Create app')}
              </Button>
            </div>
          ) : null
        }
      />
      <TransferApp
        open={handoffOpen}
        onOpenChange={(open) => {
          setHandoffOpen(open)
          if (!open) setHandoffAppId(undefined)
        }}
        partnerId={partnerId}
        targetClientId={handoffTargetClientId}
        initialResourceId={handoffAppId}
      />
    </div>
  )
}
