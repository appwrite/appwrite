import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate, useParams } from '@tanstack/react-router'
import { GitPullRequest } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canWriteReviewRepositories } from '@/lib/console-access-checks'
import {
  useOrganizationScopes,
  useProject,
  useReviewRepositories,
  useReviews,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import { useT } from '@/lib/i18n/translate'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { Repositories } from './_components/Repositories'
import { ReviewsTable } from './_components/ReviewsTable'

export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const location = useLocation()
  const navigate = useNavigate()

  const activeTab = location.pathname.split('/').includes('repositories')
    ? 'repositories'
    : 'reviews'

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteReviewRepositories(access, features)
  const writeTooltip = canWrite
    ? undefined
    : t("You don't have permission to add repositories.")

  // Keep the current page visible until the next one has loaded.
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const { isFetching } = useReviews(
    activeTab === 'reviews' ? projectId : null,
    requestedPage - 1,
    pageSize,
  )
  const {
    reviews,
    total,
    isLoading: reviewsLoading,
  } = useReviews(
    activeTab === 'reviews' ? projectId : null,
    displayedPage - 1,
    pageSize,
  )
  useEffect(() => {
    if (!isFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [isFetching, requestedPage, displayedPage])

  const { total: repositoriesTotal, isLoading: repositoriesLoading } =
    useReviewRepositories(projectId)

  const [search, setSearch] = useState('')
  const debouncedSearch = useDebouncedValue(search, 300)

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
    ],
    [projectId, repositoriesTotal, t],
  )

  const showRepositories = () =>
    navigate({
      to: '/projects/$projectId/reviews/repositories',
      params: { projectId },
    })

  // Past reviews stay listed after the last repository is removed, so the intro
  // only shows while there is nothing to list. The repositories tab is where
  // repositories are added, so it never shows the intro.
  const noRepositories =
    activeTab === 'reviews' &&
    !repositoriesLoading &&
    repositoriesTotal === 0 &&
    !reviewsLoading &&
    total === 0

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Review')}
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
        hideToolbar={noRepositories}
        {...(activeTab === 'repositories'
          ? {
              searchPlaceholder: t('Search GitHub repositories...'),
              searchValue: search,
              onSearchChange: setSearch,
            }
          : {
              createLabel: t('Add repository'),
              onCreate: showRepositories,
              createDisabled: !canWrite,
              createDisabledTooltip: writeTooltip,
            })}
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {noRepositories ? (
          <EmptyState
            icon={GitPullRequest}
            variant="card"
            title={t('Review pull requests with AI')}
            description={t(
              'Add a GitHub repository and every pull request gets a review with findings, a verdict, and a merge confidence grade. Mention the app in a comment to ask it anything.',
            )}
            action={
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={!canWrite}
                title={writeTooltip}
                onClick={showRepositories}
              >
                {t('Choose repositories')}
              </Button>
            }
          />
        ) : activeTab === 'repositories' ? (
          <Repositories
            projectId={projectId}
            canWrite={canWrite}
            search={debouncedSearch.trim()}
          />
        ) : reviews.length > 0 ? (
          <>
            <ReviewsTable projectId={projectId} reviews={reviews} />
            <Pagination
              currentPage={displayedPage}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={setRequestedPage}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setRequestedPage(1)
                setDisplayedPage(1)
              }}
              itemLabel={t('reviews')}
            />
          </>
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
