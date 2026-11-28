import { useEffect, useMemo } from 'react'
import { Link } from '@tanstack/react-router'
import { GitPullRequest, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  useInstallation,
  useRepositoryReviews,
  useReviewedRepository,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { isReviewVcsProvider } from '@/lib/vcs/providers'
import {
  normalizeRootDirectory,
  reviewTouchesDirectory,
} from '@/lib/review-paths'
import {
  RepositoryReviewCard,
  type ReviewedResource,
} from './RepositoryReviewCard'
import { ReviewsTable } from './ReviewsTable'

/**
 * Pages loaded on their own while too few of the latest reviews belong to the
 * resource; past that, "Load more" asks for the next one.
 */
const AUTO_LOAD_PAGES = 4

/** Reviews to show before the tab stops loading pages on its own. */
const AUTO_LOAD_TARGET = 10

type ResourceReviewsProps = {
  projectId: string
  resource: ReviewedResource
  installationId: string | undefined
  providerRepositoryId: string | undefined
  providerRootDirectory: string | undefined
}

/**
 * A function's or site's Reviews tab: code review for the repository it
 * deploys from, and the reviews of pull requests that change the resource.
 * In a repository holding several resources, that is the pull requests that
 * change a file in the resource's root directory.
 */
export function ResourceReviews({
  projectId,
  resource,
  installationId,
  providerRepositoryId,
  providerRootDirectory,
}: ResourceReviewsProps) {
  const t = useT()
  const connected = !!installationId && !!providerRepositoryId
  const { data: installation, isLoading: installationLoading } =
    useInstallation(connected ? projectId : null, installationId)
  const reviewable = isReviewVcsProvider(installation?.provider)
  const { repository, isLoading: repositoryLoading } = useReviewedRepository(
    reviewable ? projectId : null,
    providerRepositoryId,
  )
  const {
    reviews,
    pages,
    isLoading: reviewsLoading,
    isFetchingNextPage,
    hasMore,
    loadMore,
  } = useRepositoryReviews(projectId, repository?.$id)

  const directory = normalizeRootDirectory(providerRootDirectory)
  const shown = useMemo(
    () => reviews.filter((review) => reviewTouchesDirectory(review, directory)),
    [reviews, directory],
  )

  // The repository's other resources may own most of the latest reviews, so
  // look a few pages further before asking for "Load more".
  const autoLoad =
    hasMore &&
    !isFetchingNextPage &&
    shown.length < AUTO_LOAD_TARGET &&
    pages < AUTO_LOAD_PAGES
  useEffect(() => {
    if (autoLoad) loadMore()
  }, [autoLoad, loadMore])

  const settingsLink =
    resource.type === 'function' ? (
      <Link
        to="/projects/$projectId/functions/$functionId/settings/git"
        params={{ projectId, functionId: resource.id }}
      >
        {t('Connect repository')}
      </Link>
    ) : (
      <Link
        to="/projects/$projectId/sites/$siteId/settings/git"
        params={{ projectId, siteId: resource.id }}
      >
        {t('Connect repository')}
      </Link>
    )

  const body = () => {
    if (!connected) {
      return (
        <EmptyState
          icon={GitPullRequest}
          variant="card"
          title={t('No repository connected')}
          description={
            resource.type === 'function'
              ? t(
                  'Connect a repository to this function to review its pull requests.',
                )
              : t(
                  'Connect a repository to this site to review its pull requests.',
                )
          }
          action={
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              asChild
            >
              {settingsLink}
            </Button>
          }
        />
      )
    }

    if (installationLoading || repositoryLoading) {
      return <Loading />
    }

    if (!reviewable) {
      return (
        <EmptyState
          icon={GitPullRequest}
          variant="card"
          title={t('Code review does not run on this provider')}
          description={t(
            'Code review runs on GitHub, GitLab, Gitea, Bitbucket and Origin repositories.',
          )}
        />
      )
    }

    return (
      <div className="space-y-4">
        <RepositoryReviewCard
          projectId={projectId}
          installationId={installationId}
          providerRepositoryId={providerRepositoryId}
          resource={resource}
          showReviewsLink={false}
        />
        {!repository ? (
          <EmptyState
            icon={GitPullRequest}
            variant="card"
            title={t('Code review is off')}
            description={t(
              'Turn on code review to have each pull request reviewed when it is opened or gets new commits.',
            )}
          />
        ) : (
          <>
            {directory !== '' && (
              <p className="text-[13px] text-muted-foreground">
                {t('Pull requests that change files in')}{' '}
                <span className="font-mono text-foreground">{directory}</span>
              </p>
            )}
            {reviewsLoading || (shown.length === 0 && autoLoad) ? (
              <Loading />
            ) : shown.length > 0 ? (
              <ReviewsTable projectId={projectId} reviews={shown} />
            ) : hasMore ? null : (
              <EmptyState
                icon={GitPullRequest}
                variant="card"
                title={t('No reviews yet')}
                description={
                  directory !== ''
                    ? t(
                        'Reviews show up here when a pull request that changes this directory is opened or gets new commits.',
                      )
                    : t(
                        'Reviews show up here when a pull request is opened or gets new commits.',
                      )
                }
              />
            )}
            {hasMore && (
              <div className="flex flex-col items-center gap-2">
                {shown.length === 0 && (
                  <p className="text-[13px] text-muted-foreground">
                    {t('No reviews of this directory among the latest ones.')}
                  </p>
                )}
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={loadMore}
                  disabled={isFetchingNextPage}
                >
                  {isFetchingNextPage && (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  )}
                  {t('Load more')}
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6 sm:py-6">
      {body()}
    </div>
  )
}

function Loading() {
  return (
    <div className="flex items-center justify-center py-12">
      <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
    </div>
  )
}
