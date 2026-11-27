import { useEffect, useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { Package } from 'lucide-react'
import { ServiceHeader } from '../shared/ServiceHeader'
import { ServiceListViewToggle } from '../shared/ServiceListViewToggle'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useServiceListViewMode } from '@/hooks/use-service-list-view-mode'
import type { DistributionAppList } from '@/lib/appwrite/distribution'
import { canCreateDistributionApp } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  useDistributionApps,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { MIN_SEARCH_LENGTH } from '@/lib/table-filters'
import { AppsEmptyState } from './_components/AppsEmptyState'
import { AppsGrid } from './_components/AppsGrid'
import { AppsTable } from './_components/AppsTable'
import { useTabs } from './_components/tabs'

const SEARCH_DEBOUNCE_MS = 300

export function View({ initialData }: { initialData?: DistributionAppList }) {
  const t = useT()
  const { projectId = '' } = useParams({ strict: false })
  const navigate = useNavigate()
  const tabs = useTabs(projectId)
  const { viewMode, setViewMode } = useServiceListViewMode('distribution')
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canCreate = canCreateDistributionApp(access, features)

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)

  useEffect(() => {
    const timeout = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      setSearch(trimmed)
      setRequestedPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timeout)
  }, [searchInput])

  const {
    apps: fetchedApps,
    total: fetchedTotal,
    isLoading,
    isFetching,
    isPlaceholderData,
  } = useDistributionApps(
    projectId,
    requestedPage - 1,
    GRID_DEFAULT_PAGE_SIZE,
    search,
  )

  useEffect(() => {
    if (!isFetching && !isPlaceholderData) setDisplayedPage(requestedPage)
  }, [isFetching, isPlaceholderData, requestedPage])

  const showsInitialData =
    !!initialData && isLoading && requestedPage === 1 && !search
  const apps = showsInitialData ? initialData.apps : fetchedApps
  const total = showsInitialData ? initialData.total : fetchedTotal
  const showsLoading = isLoading && !showsInitialData
  const showsFirstRun =
    !showsLoading &&
    !isPlaceholderData &&
    !search &&
    !searchInput.trim() &&
    apps.length === 0

  const openCreate = () =>
    navigate({
      to: '/projects/$projectId/distribution/create',
      params: { projectId },
    })
  const createDisabledTooltip = canCreate
    ? undefined
    : t("You don't have permission to create apps.")

  return (
    <div className="flex h-full flex-col">
      <ServiceHeader
        title={t('Distribution')}
        tabs={tabs}
        activeTab="apps"
        searchPlaceholder={t('Search apps...')}
        searchValue={searchInput}
        onSearchChange={setSearchInput}
        createLabel={t('Create app')}
        onCreate={openCreate}
        createDisabled={!canCreate}
        createDisabledTooltip={createDisabledTooltip}
        fullWidthBorder
        hideToolbar={showsFirstRun}
        rightContent={
          <ServiceListViewToggle
            viewMode={viewMode}
            onViewModeChange={setViewMode}
          />
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
        {showsLoading ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              {t('Loading apps...')}
            </p>
          </div>
        ) : showsFirstRun ? (
          <AppsEmptyState
            onCreate={openCreate}
            createDisabled={!canCreate}
            createDisabledTooltip={createDisabledTooltip}
          />
        ) : apps.length === 0 ? (
          <EmptyState
            icon={Package}
            hasFilters
            variant="card"
            title={t('No apps found')}
            description={t('Try a different name or ID.')}
          />
        ) : (
          <>
            {viewMode === 'list' ? (
              <AppsTable projectId={projectId} apps={apps} />
            ) : (
              <AppsGrid projectId={projectId} apps={apps} />
            )}
            <Pagination
              currentPage={displayedPage}
              totalItems={total}
              pageSize={GRID_DEFAULT_PAGE_SIZE}
              onPageChange={setRequestedPage}
              onPageSizeChange={() => setRequestedPage(1)}
              showPageSizeSelector={false}
              itemLabel={t('apps')}
            />
          </>
        )}
      </div>
    </div>
  )
}
