import { useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Boxes, Upload } from 'lucide-react'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { CreateProjectDialog } from '@/components/pages/organizations/$orgId/overview/CreateProjectDialog'
import { TransferProject } from '@/components/pages/partners/$partnerId/_components/TransferProject'
import { PartnerProjectsSpreadsheet } from '@/components/pages/partners/$partnerId/_components/ProjectsSpreadsheet'
import { usePartnerPinnedProjects } from '@/components/pages/partners/$partnerId/_components/usePartnerPinnedProjects'
import {
  PartnerListPaginationFooter,
  PARTNER_LIST_PAGE_SIZE,
} from '@/components/pages/partners/$partnerId/_components/PartnerListPaginationFooter'
import {
  PartnerProjectsPaneHeader,
  usePartnerProjectsFilterState,
} from '@/components/pages/partners/$partnerId/_components/ProjectsPaneHeader'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canCreateProject,
  canShowProjectSettings,
} from '@/lib/console-access-checks'
import { isUsageStatsCapabilityResolved } from '@/lib/console-profiles'
import { useT } from '@/lib/i18n/translate'
import {
  activeProjectsQueryOptions,
  mapProjectToListItem,
  nextActiveProjectsSort,
  ACTIVE_PROJECTS_DEFAULT_SORT_BY,
  ACTIVE_PROJECTS_DEFAULT_SORT_ORDER,
  useOrganizationPlan,
  useOrganizationScopes,
  useProjectListAuthMauUsage,
  useProjectListBandwidthUsage,
  useProjectListComputeUsage,
  useProjectListExecutionsUsage,
  useProjectListRequestsUsage,
  useProjectListStorageUsage,
} from '@/lib/react-query/hooks'
import { PARTNER_PANE_HEADER_BUTTON_CLASSNAME } from '@/components/pages/partners/$partnerId/_components/pane-header'

export function DevelopmentProjects({ partnerId }: { partnerId: string }) {
  const t = useT()
  const queryClient = useQueryClient()
  const [createOpen, setCreateOpen] = useState(false)
  const [transferOpen, setTransferOpen] = useState(false)
  const [handoffProjectId, setHandoffProjectId] = useState<string | undefined>()
  const [searchValue, setSearchValue] = useState('')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(PARTNER_LIST_PAGE_SIZE)
  const [sortBy, setSortBy] = useState(ACTIVE_PROJECTS_DEFAULT_SORT_BY)
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>(
    ACTIVE_PROJECTS_DEFAULT_SORT_ORDER,
  )
  const resetListToFirstPage = () => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }
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
  } = usePartnerProjectsFilterState(resetListToFirstPage)
  const search = searchValue.trim()
  const searchActive = search.length > 0
  const listFiltered = searchActive || hasFilters
  const {
    pinnedIds,
    pinnedProjects,
    listExcludePinnedIds,
    canPinProjects: canPin,
    isPinPending,
    handlePinProject,
  } = usePartnerPinnedProjects(partnerId, listFiltered)
  const { features, isSelfHosted } = useConsoleProfile()
  const { access } = useOrganizationScopes(partnerId)
  const { plan } = useOrganizationPlan(partnerId)
  const requestedQuery = useQuery(
    activeProjectsQueryOptions(
      partnerId,
      requestedPage - 1,
      pageSize,
      search,
      listExcludePinnedIds,
      undefined,
      sortBy,
      sortOrder,
      filterQueries,
    ),
  )
  const displayedQuery = useQuery(
    activeProjectsQueryOptions(
      partnerId,
      displayedPage - 1,
      pageSize,
      search,
      listExcludePinnedIds,
      undefined,
      sortBy,
      sortOrder,
      filterQueries,
    ),
  )

  useEffect(() => {
    if (!requestedQuery.isFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedQuery.isFetching, requestedPage, displayedPage])

  const projects = useMemo(
    () => (displayedQuery.data?.projects ?? []).map(mapProjectToListItem),
    [displayedQuery.data?.projects],
  )
  const total = displayedQuery.data?.total ?? 0
  const isLoading = displayedQuery.isLoading && projects.length === 0 && pinnedProjects.length === 0
  const showPagination = projects.length > 0

  useEffect(() => {
    const totalPages = Math.max(1, Math.ceil(total / pageSize))
    if (total > 0 && requestedPage > totalPages) {
      setRequestedPage(totalPages)
    }
  }, [total, pageSize, requestedPage])
  const projectIds = useMemo(
    () => [
      ...pinnedProjects.map((project) => project.$id),
      ...projects.map((project) => project.$id),
    ],
    [pinnedProjects, projects],
  )

  const showProjectUsageCharts =
    features.usageStats ||
    (isSelfHosted && !isUsageStatsCapabilityResolved())
  const projectRequestsUsageById = useProjectListRequestsUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectBandwidthUsageById = useProjectListBandwidthUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectExecutionsUsageById = useProjectListExecutionsUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectStorageUsageById = useProjectListStorageUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectComputeUsageById = useProjectListComputeUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectAuthMauUsageById = useProjectListAuthMauUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )

  const canCreate = canCreateProject(access, features)
  const showSettingsTab = canShowProjectSettings(access, features)
  const createDisabledTooltip = canCreate
    ? undefined
    : t("You don't have permission to create projects.")

  const handleProjectDeleted = async () => {
    await queryClient.refetchQueries({
      queryKey: ['projects', 'active', partnerId],
    })
    await queryClient.refetchQueries({
      queryKey: ['projects', 'pinned', partnerId],
    })
  }

  const handleReload = () => {
    void queryClient.refetchQueries({
      queryKey: ['projects', 'active', partnerId],
    })
    void queryClient.refetchQueries({
      queryKey: ['projects', 'pinned', partnerId],
    })
  }

  const createButton = (
    <Button
      size="sm"
      variant="brandCta"
      className={PARTNER_PANE_HEADER_BUTTON_CLASSNAME}
      disabled={!canCreate}
      onClick={() => setCreateOpen(true)}
      {...analyticsAttrs('create-project')}
    >
      {t('Create project')}
    </Button>
  )

  const transferButton = (
    <Button
      size="sm"
      variant="outline"
      className={PARTNER_PANE_HEADER_BUTTON_CLASSNAME}
      disabled={!canCreate}
      onClick={() => {
        setHandoffProjectId(undefined)
        setTransferOpen(true)
      }}
      {...analyticsAttrs('handoff-project')}
    >
      <Upload className="h-3.5 w-3.5" />
      {t('Handoff project')}
    </Button>
  )

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setRequestedPage(1)
    setDisplayedPage(1)
  }

  const handleSortColumn = (columnKey: string) => {
    const next = nextActiveProjectsSort(sortBy, sortOrder, columnKey)
    setSortBy(next.sortBy)
    setSortOrder(next.sortOrder)
    setRequestedPage(1)
    setDisplayedPage(1)
  }

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      <PartnerProjectsPaneHeader
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        filterMap={filterMap}
        filtersOpen={filtersOpen}
        onFiltersOpenChange={setFiltersOpen}
        onRemoveFilter={removeFilter}
        onClearAll={clearAll}
        onApplyFilter={applyFilter}
        onApplyQuery={applyQuery}
        teamId={partnerId}
        onReload={handleReload}
        isReloading={requestedQuery.isFetching || displayedQuery.isFetching}
        actions={
          createDisabledTooltip ? (
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">{transferButton}</span>
                </TooltipTrigger>
                <TooltipContent className="max-w-xs text-xs">
                  {createDisabledTooltip}
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            transferButton
          )
        }
        primaryAction={
          createDisabledTooltip ? (
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">{createButton}</span>
                </TooltipTrigger>
                <TooltipContent className="max-w-xs text-xs">
                  {createDisabledTooltip}
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            createButton
          )
        }
      />
      {isLoading ? (
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading projects...')}
          </p>
        </div>
      ) : projects.length === 0 && pinnedProjects.length === 0 ? (
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          <EmptyState
            icon={Boxes}
            title={listFiltered ? undefined : 'No projects yet'}
            description={
              listFiltered
                ? undefined
                : 'Create a project here to build and test before handing it off to a client.'
            }
            isEmpty={!listFiltered}
            hasFilters={listFiltered}
            variant="card"
            action={
              !listFiltered && canCreate ? (
                <Button
                  size="sm"
                  variant="brandCta"
                  onClick={() => setCreateOpen(true)}
                >
                  {t('Create project')}
                </Button>
              ) : undefined
            }
          />
        </div>
      ) : (
        <PartnerProjectsSpreadsheet
          projects={projects}
          pinnedProjects={pinnedProjects}
          showSettingsTab={showSettingsTab}
          canDeleteProject={canCreate}
          onProjectDeleted={handleProjectDeleted}
          onHandoff={
            canCreate
              ? (projectId) => {
                  setHandoffProjectId(projectId)
                  setTransferOpen(true)
                }
              : undefined
          }
          handoffIcon={Upload}
          showUsage={showProjectUsageCharts}
          projectRequestsUsageById={projectRequestsUsageById}
          projectBandwidthUsageById={projectBandwidthUsageById}
          projectExecutionsUsageById={projectExecutionsUsageById}
          projectStorageUsageById={projectStorageUsageById}
          projectComputeUsageById={projectComputeUsageById}
          projectAuthMauUsageById={projectAuthMauUsageById}
          sortBy={sortBy}
          sortOrder={sortOrder}
          onSortColumn={handleSortColumn}
          canPinProjects={canPin}
          pinnedIds={pinnedIds}
          onPinProject={handlePinProject}
          isPinPending={isPinPending}
        />
      )}
      {showPagination ? (
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
          itemLabel={t('projects')}
        />
      ) : null}
      <CreateProjectDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        teamId={partnerId}
        organizationPlan={plan}
        currentProjectsCount={displayedQuery.data?.total ?? projects.length}
        openCreatedInNewTab
      />
      <TransferProject
        open={transferOpen}
        onOpenChange={(open) => {
          setTransferOpen(open)
          if (!open) setHandoffProjectId(undefined)
        }}
        partnerId={partnerId}
        initialResourceId={handoffProjectId}
      />
    </div>
  )
}
