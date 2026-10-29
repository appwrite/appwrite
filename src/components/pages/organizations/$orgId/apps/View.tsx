import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { Loader2, Package, Plus, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  PartnerListPaginationFooter,
  PARTNER_LIST_PAGE_SIZE,
} from '@/components/pages/partners/$partnerId/_components/PartnerListPaginationFooter'
import {
  MARKETPLACE_APPS_LIMIT,
  useCreateOrganizationApp,
  useOrganizationApps,
  useOrganizations,
} from '@/lib/react-query/hooks'
import { MarketplaceAppCard } from '../marketplace/_components/MarketplaceAppCard'
import { CreateMarketplaceApp } from '../marketplace/_components/CreateMarketplaceApp'
import {
  OrganizationAppContextMenu,
  OrganizationAppRowActionsMenu,
} from './_components/OrganizationAppContextMenu'
import type { CreateMarketplaceAppInput } from '../marketplace/_components/CreateMarketplaceApp'
import type { MarketplaceApp } from '@/lib/marketplace/types'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { buildConsoleUrl, openInNewTab } from '@/lib/utils/context-menu'

type ViewProps = {
  organizationId?: string
  openAppsInNewTab?: boolean
  /** Skip page title / create chrome when the parent already provides a header. */
  hideChrome?: boolean
  /**
   * Partner pane empty state: same EmptyState card, spacing, and CTA as
   * Projects/Members. Org overview keeps the existing empty layout.
   */
  embedded?: boolean
  createOpen?: boolean
  onCreateOpenChange?: (open: boolean) => void
  search?: string
  filterQueries?: string[]
  hasFilters?: boolean
  /** Partner pane: custom empty CTA. `null` hides the default Create button. */
  emptyAction?: ReactNode | null
  onHandoff?: (appId: string) => void
  handoffIcon?: LucideIcon
  showContextMenus?: boolean
}

export function View({
  organizationId,
  openAppsInNewTab = false,
  hideChrome = false,
  embedded = false,
  createOpen,
  onCreateOpenChange,
  search,
  filterQueries,
  hasFilters = false,
  emptyAction,
  onHandoff,
  handoffIcon,
  showContextMenus = false,
}: ViewProps = {}) {
  const t = useT()
  const { orgId: orgIdFromParams } = useParams({ strict: false })
  const orgId = organizationId ?? orgIdFromParams
  const navigate = useNavigate()
  const { organizations } = useOrganizations()
  const [uncontrolledCreateOpen, setUncontrolledCreateOpen] = useState(false)
  const createDialogOpen = createOpen ?? uncontrolledCreateOpen
  const setCreateDialogOpen = onCreateOpenChange ?? setUncontrolledCreateOpen
  const paneMode = hideChrome || embedded
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(PARTNER_LIST_PAGE_SIZE)
  const listPageSize = paneMode ? pageSize : MARKETPLACE_APPS_LIMIT
  const requestedListPage = paneMode ? requestedPage - 1 : 0
  const displayedListPage = paneMode ? displayedPage - 1 : 0
  const filterQueriesKey = filterQueries?.join('\0') ?? ''

  const teamNamesById = useMemo(
    () =>
      Object.fromEntries(
        organizations.map((org) => [org.$id, org.name] as const),
      ),
    [organizations],
  )

  const requestedApps = useOrganizationApps(
    orgId,
    teamNamesById,
    search,
    filterQueries,
    requestedListPage,
    listPageSize,
  )
  const displayedApps = useOrganizationApps(
    orgId,
    teamNamesById,
    search,
    filterQueries,
    displayedListPage,
    listPageSize,
  )
  const apps = displayedApps.apps
  const total = displayedApps.total
  const isLoading = displayedApps.isLoading
  const isFetching = requestedApps.isFetching || displayedApps.isFetching

  useEffect(() => {
    if (!paneMode) return
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [paneMode, search, filterQueriesKey])

  useEffect(() => {
    if (!requestedApps.isFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedApps.isFetching, requestedPage, displayedPage])

  useEffect(() => {
    if (!paneMode) return
    const totalPages = Math.max(1, Math.ceil(total / pageSize))
    if (total > 0 && requestedPage > totalPages) {
      setRequestedPage(totalPages)
    }
  }, [paneMode, total, pageSize, requestedPage])

  const createAppMutation = useCreateOrganizationApp(orgId)

  const openApp = (app: MarketplaceApp) => {
    if (!orgId) return
    const href = `/organizations/${orgId}/apps/${app.$id}`
    if (openAppsInNewTab) {
      openInNewTab(buildConsoleUrl(href))
      return
    }
    navigate({
      to: '/organizations/$orgId/apps/$appId',
      params: { orgId, appId: app.$id },
    })
  }

  const handleCreateApp = async (input: CreateMarketplaceAppInput) => {
    try {
      const app = await createAppMutation.mutateAsync(input)
      setCreateDialogOpen(false)
      toast.success(t('App created'))
      if (orgId && app?.$id) {
        const href = `/organizations/${orgId}/apps/${app.$id}`
        if (openAppsInNewTab) {
          openInNewTab(buildConsoleUrl(href))
        } else {
          navigate({
            to: '/organizations/$orgId/apps/$appId',
            params: { orgId, appId: app.$id },
          })
        }
      }
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create app')))
    }
  }

  const createDialog = (
    <CreateMarketplaceApp
      open={createDialogOpen}
      onOpenChange={setCreateDialogOpen}
      onCreate={handleCreateApp}
      isSubmitting={createAppMutation.isPending}
      organizationId={orgId}
    />
  )

  const createAppButton = (
    <Button
      size="sm"
      onClick={() => setCreateDialogOpen(true)}
      {...analyticsAttrs('create-marketplace-app')}
    >
      {t('Create app')}
    </Button>
  )

  if (isLoading && apps.length === 0) {
    return paneMode ? (
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          <p className="text-[13px] text-muted-foreground">{t('Loading apps...')}</p>
        </div>
        {createDialog}
      </div>
    ) : (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        {createDialog}
      </div>
    )
  }

  const listHasFilters = hasFilters
  const list =
    apps.length === 0 ? (
      embedded ? (
        <EmptyState
          icon={Package}
          title={listHasFilters ? undefined : t('No apps yet')}
          description={
            listHasFilters
              ? undefined
              : emptyAction !== undefined
                ? t('Create an app here, or hand one off from development.')
                : t(
                    'Create an app to share it with other organizations on the marketplace.',
                  )
          }
          isEmpty={!listHasFilters}
          hasFilters={listHasFilters}
          variant="card"
          action={
            !listHasFilters
              ? emptyAction === undefined
                ? createAppButton
                : emptyAction ?? undefined
              : undefined
          }
        />
      ) : (
        <EmptyState
          icon={Package}
          title={t('No apps yet')}
          description={t(
            'Create an app to share it with other organizations on the marketplace.',
          )}
          action={createAppButton}
          variant="card"
        />
      )
    ) : (
      <div className="relative">
        {isFetching && !paneMode && (
          <div className="absolute end-0 top-0 z-10">
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          </div>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {apps.map((app) => {
            const card = (
              <MarketplaceAppCard
                app={app}
                onClick={() => openApp(app)}
              />
            )
            if (!orgId || !showContextMenus) {
              return <div key={app.$id}>{card}</div>
            }
            return (
              <OrganizationAppContextMenu
                key={app.$id}
                orgId={orgId}
                app={app}
                openResourceInNewTab={openAppsInNewTab}
                onHandoff={onHandoff}
                handoffIcon={handoffIcon}
              >
                <div className="relative">
                  {card}
                  <div className="absolute end-2 top-2 z-10">
                    <OrganizationAppRowActionsMenu />
                  </div>
                </div>
              </OrganizationAppContextMenu>
            )
          })}
        </div>
      </div>
    )

  if (paneMode) {
    return (
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          {list}
        </div>
        {apps.length > 0 ? (
          <PartnerListPaginationFooter
            currentPage={displayedPage}
            totalItems={total}
            pageSize={pageSize}
            onPageChange={setRequestedPage}
            onPageSizeChange={(size) => {
              setRequestedPage(1)
              setDisplayedPage(1)
              setPageSize(size)
            }}
            itemLabel={t('apps')}
          />
        ) : null}
        {createDialog}
      </div>
    )
  }

  return (
    <div>
      <div className="flex items-center justify-between gap-4 mb-6">
        <div>
          <h2 className="text-[15px] font-semibold text-foreground">{t('Apps')}</h2>
          <p className="text-[13px] text-muted-foreground mt-1">
            {t('OAuth2 apps published by your organization to the marketplace.')}
          </p>
        </div>
        <Button
          size="sm"
          onClick={() => setCreateDialogOpen(true)}
          {...analyticsAttrs('create-marketplace-app')}
        >
          <Plus className="me-1.5 h-3.5 w-3.5" />
          {t('Create app')}
        </Button>
      </div>
      {list}
      {createDialog}
    </div>
  )
}
