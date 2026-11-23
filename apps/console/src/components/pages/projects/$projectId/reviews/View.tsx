import { useMemo, useState } from 'react'
import {
  useLocation,
  useNavigate,
  useParams,
  useSearch,
} from '@tanstack/react-router'
import { useQueries } from '@tanstack/react-query'
import { GitPullRequest } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { Pagination } from '@/components/global/shared/Pagination'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canWriteReviewRepositories } from '@/lib/console-access-checks'
import {
  fetchRepository,
  useOrganizationScopes,
  useProject,
  useReviewLearnings,
  useReviewRepositories,
  useReviews,
} from '@/lib/react-query/hooks'
import {
  DEFAULT_PAGE_SIZE,
  DEFAULT_STALE_TIME,
} from '@/lib/react-query/hooks/constants'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import { useT } from '@/lib/i18n/translate'
import {
  mapToQueryParam,
  queryParamToMap,
  reviewsFilterColumns,
  type CompactFilterKey,
} from '@/lib/table-filters'
import { cn } from '@/lib/utils'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { Credits } from './_components/Credits'
import { Learnings } from './_components/Learnings'
import { Performance } from './_components/Performance'
import { Repositories } from './_components/Repositories'
import { ReviewsEmptyState } from './_components/ReviewsEmptyState'
import { ReviewsTable } from './_components/ReviewsTable'

/** Repositories offered in the repository filter: the first page the project reviews. */
const REPOSITORY_FILTER_LIMIT = 100

type ReviewsSearch = { page?: number; query?: string }

export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const location = useLocation()
  const navigate = useNavigate()
  const search = useSearch({ strict: false }) as ReviewsSearch

  const segments = location.pathname.split('/')
  const activeTab = segments.includes('repositories')
    ? 'repositories'
    : segments.includes('learnings')
      ? 'learnings'
      : segments.includes('performance')
        ? 'performance'
        : 'reviews'

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteReviewRepositories(access, features)
  const writeTooltip = canWrite
    ? undefined
    : t("You don't have permission to add repositories.")

  // Page and filters live in the URL, so they survive a visit to a review.
  const page = activeTab === 'reviews' ? (search.page ?? 1) : 1
  const filterMap = useMemo(
    () =>
      queryParamToMap(activeTab === 'reviews' ? (search.query ?? null) : null),
    [activeTab, search.query],
  )
  const filterQueries = useMemo(
    () => (filterMap.size > 0 ? Array.from(filterMap.values()) : undefined),
    [filterMap],
  )
  const hasFilters = filterMap.size > 0

  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const {
    reviews,
    total,
    isLoading: reviewsLoading,
    isFetching: reviewsFetching,
    refetch: refetchReviews,
  } = useReviews(
    activeTab === 'reviews' ? projectId : null,
    page - 1,
    pageSize,
    filterQueries,
  )

  const { total: repositoriesTotal, isLoading: repositoriesLoading } =
    useReviewRepositories(projectId)
  const { total: learningsTotal } = useReviewLearnings(projectId)

  // A review stores its repository by ID; the filter names them, which takes a
  // lookup on GitHub per repository, so only while the filters are in use.
  const [filtersOpen, setFiltersOpen] = useState(false)
  const namesNeeded = activeTab === 'reviews' && (filtersOpen || hasFilters)
  const { repositories: filterRepositories } = useReviewRepositories(
    namesNeeded ? projectId : null,
    0,
    REPOSITORY_FILTER_LIMIT,
  )
  const names = useQueries({
    queries: filterRepositories.map((repository) => ({
      queryKey: [
        'vcs',
        'repository',
        projectId,
        repository.installationId,
        repository.providerRepositoryId,
      ],
      queryFn: () =>
        fetchRepository(
          projectId,
          repository.installationId,
          repository.providerRepositoryId,
        ),
      staleTime: DEFAULT_STALE_TIME,
      retry: false,
    })),
  })
  const filterColumns = useMemo(
    () =>
      reviewsFilterColumns(
        filterRepositories.map((repository, index) => {
          const provider = names[index]?.data
          return {
            value: repository.$id,
            label: provider
              ? `${provider.organization}/${provider.name}`
              : repository.providerRepositoryId,
          }
        }),
      ),
    [filterRepositories, names],
  )

  const [repositorySearch, setRepositorySearch] = useState('')
  const debouncedRepositorySearch = useDebouncedValue(repositorySearch, 300)

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'reviews',
        label: t('Reviews'),
        to: '/projects/$projectId/reviews/',
        params: { projectId },
      },
      {
        id: 'repositories',
        label: t('Repositories'),
        count: repositoriesTotal,
        to: '/projects/$projectId/reviews/repositories',
        params: { projectId },
      },
      {
        id: 'learnings',
        label: t('Learnings'),
        count: learningsTotal,
        to: '/projects/$projectId/reviews/learnings',
        params: { projectId },
      },
      {
        id: 'performance',
        label: t('Performance'),
        to: '/projects/$projectId/reviews/performance',
        params: { projectId },
      },
    ],
    [projectId, repositoriesTotal, learningsTotal, t],
  )

  const showRepositories = () =>
    navigate({
      to: '/projects/$projectId/reviews/repositories',
      params: { projectId },
    })

  const navigateReviews = (next: ReviewsSearch) =>
    navigate({
      to: location.pathname,
      search: (prev: ReviewsSearch) => {
        const merged = { ...prev, ...next }
        return {
          page: merged.page && merged.page > 1 ? merged.page : undefined,
          query: merged.query || undefined,
        }
      },
      replace: true,
    })

  const applyFilter = (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const next = new Map(filterMap)
    if (replaceKey) next.delete(replaceKey)
    next.set(key, queryStr)
    navigateReviews({ query: mapToQueryParam(next), page: 1 })
  }
  const removeFilter = (key: CompactFilterKey) => {
    const next = new Map(filterMap)
    next.delete(key)
    navigateReviews({
      query: next.size > 0 ? mapToQueryParam(next) : undefined,
      page: 1,
    })
  }
  const clearFilters = () => navigateReviews({ query: undefined, page: 1 })

  // Past reviews stay listed after the last repository is removed, so the intro
  // only shows while there is nothing to list. The repositories tab is where
  // repositories are added, so it never shows the intro.
  const firstRun =
    activeTab === 'reviews' &&
    !hasFilters &&
    !repositoriesLoading &&
    repositoriesTotal === 0 &&
    !reviewsLoading &&
    total === 0

  // Tabs without a toolbar keep the same gap under the tab bar as the others.
  const hasToolbar =
    !firstRun && (activeTab === 'reviews' || activeTab === 'repositories')

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Review')}
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
        hideToolbar={!hasToolbar}
        {...(activeTab === 'repositories'
          ? {
              searchPlaceholder: t('Search GitHub repositories...'),
              searchValue: repositorySearch,
              onSearchChange: setRepositorySearch,
            }
          : activeTab === 'reviews'
            ? {
                filterTrigger: (
                  <FiltersPopover
                    open={filtersOpen}
                    onOpenChange={setFiltersOpen}
                    columns={filterColumns}
                    filterMap={filterMap}
                    onRemoveFilter={removeFilter}
                    onClearAll={clearFilters}
                    onApplyFilter={applyFilter}
                    resourceLabel="reviews"
                    filterScope="reviews"
                    onApplyQuery={(query) =>
                      navigateReviews({ query, page: 1 })
                    }
                    teamId={project?.teamId}
                  />
                ),
                showRefresh: true,
                onRefresh: () => void refetchReviews(),
                isRefreshing: reviewsFetching && !reviewsLoading,
                createLabel: t('Add repository'),
                onCreate: showRepositories,
                createDisabled: !canWrite,
                createDisabledTooltip: writeTooltip,
              }
            : {})}
      />

      <div
        className={cn(
          'mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6',
          !hasToolbar && !firstRun && 'pt-4 sm:pt-6',
        )}
      >
        {activeTab === 'reviews' && !firstRun && (
          <Credits projectId={projectId} organizationId={project?.teamId} />
        )}
        {firstRun ? (
          <ReviewsEmptyState
            onChoose={showRepositories}
            chooseDisabled={!canWrite}
            chooseDisabledTooltip={writeTooltip}
          />
        ) : activeTab === 'performance' ? (
          <Performance projectId={projectId} />
        ) : activeTab === 'learnings' ? (
          <Learnings projectId={projectId} canWrite={canWrite} />
        ) : activeTab === 'repositories' ? (
          <Repositories
            projectId={projectId}
            canWrite={canWrite}
            search={debouncedRepositorySearch.trim()}
          />
        ) : reviews.length > 0 ? (
          <>
            <ReviewsTable projectId={projectId} reviews={reviews} />
            <Pagination
              currentPage={page}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={(next) => navigateReviews({ page: next })}
              onPageSizeChange={(size) => {
                setPageSize(size)
                navigateReviews({ page: 1 })
              }}
              itemLabel={t('reviews')}
            />
          </>
        ) : hasFilters ? (
          <EmptyState
            icon={GitPullRequest}
            variant="card"
            hasFilters
            title={t('No reviews match your filters')}
            description={t('Try adjusting or clearing filters.')}
            action={
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={clearFilters}
              >
                {t('Clear filters')}
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={GitPullRequest}
            variant="card"
            title={t('No reviews yet')}
            description={t(
              'Reviews show up here when a pull request is opened or gets new commits in one of your repositories.',
            )}
            action={
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={showRepositories}
              >
                {t('View repositories')}
              </Button>
            }
          />
        )}
      </div>
    </div>
  )
}
