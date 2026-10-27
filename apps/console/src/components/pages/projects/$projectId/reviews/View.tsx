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
import { useT } from '@/lib/i18n/translate'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { AddRepository } from './_components/AddRepository'
import { RepositoriesTable } from './_components/RepositoriesTable'
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
  const { reviews, total } = useReviews(
    activeTab === 'reviews' ? projectId : null,
    displayedPage - 1,
    pageSize,
  )
  useEffect(() => {
    if (!isFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [isFetching, requestedPage, displayedPage])

  const { repositories, isLoading: repositoriesLoading } =
    useReviewRepositories(projectId)

  const [addOpen, setAddOpen] = useState(false)

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
        count: repositories.length,
        to: '/projects/$projectId/reviews/repositories',
        params: { projectId },
      },
    ],
    [projectId, repositories.length, t],
  )

  const addButton = (
    <Button
      size="sm"
      className="h-9 text-[13px]"
      disabled={!canWrite}
      title={writeTooltip}
      onClick={() => setAddOpen(true)}
    >
      {t('Add repository')}
    </Button>
  )

  const noRepositories = !repositoriesLoading && repositories.length === 0

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Review')}
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
        hideToolbar={noRepositories}
        createLabel={t('Add repository')}
        onCreate={() => setAddOpen(true)}
        createDisabled={!canWrite}
        createDisabledTooltip={writeTooltip}
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
            action={addButton}
          />
        ) : activeTab === 'repositories' ? (
          <RepositoriesTable
            projectId={projectId}
            repositories={repositories}
            canWrite={canWrite}
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
                onClick={() =>
                  navigate({
                    to: '/projects/$projectId/reviews/repositories',
                    params: { projectId },
                  })
                }
              >
                {t('View repositories')}
              </Button>
            }
          />
        )}
      </div>

      <AddRepository
        projectId={projectId}
        open={addOpen}
        onOpenChange={setAddOpen}
      />
    </div>
  )
}
