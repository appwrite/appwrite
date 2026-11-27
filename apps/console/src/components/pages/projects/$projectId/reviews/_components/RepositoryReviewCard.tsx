import { Link } from '@tanstack/react-router'
import { Gauge, GitPullRequest } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  useInstallation,
  useRepository,
  useReviewedRepository,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { isReviewVcsProvider } from '@/lib/vcs/providers'
import { ReviewToggle } from './ReviewToggle'
import { StrengthSelect } from './StrengthSelect'
import { useReviewsAccess } from './useReviewsAccess'

/** A function or site whose connected repository is reviewed. */
export type ReviewedResource =
  | { type: 'function'; id: string }
  | { type: 'site'; id: string }

type RepositoryReviewCardProps = {
  projectId: string
  installationId: string
  providerRepositoryId: string
  resource: ReviewedResource
  /** Links to the resource's Reviews tab; off on the tab itself. */
  showReviewsLink?: boolean
}

/**
 * Code review for the repository a function or site deploys from: turn it on
 * or off and pick how hard pull requests are reviewed. The setting belongs to
 * the repository, so it applies to every resource the project deploys from it.
 * Renders nothing where code review is unavailable or does not run on the
 * repository's provider.
 */
export function RepositoryReviewCard({
  projectId,
  installationId,
  providerRepositoryId,
  resource,
  showReviewsLink = true,
}: RepositoryReviewCardProps) {
  const t = useT()
  const { visible, canWrite } = useReviewsAccess(projectId)
  const { data: installation } = useInstallation(
    visible ? projectId : null,
    installationId,
  )
  const reviewable = isReviewVcsProvider(installation?.provider)
  const { repository, isLoading } = useReviewedRepository(
    reviewable ? projectId : null,
    providerRepositoryId,
  )
  const { data: provider } = useRepository(
    reviewable ? projectId : null,
    installationId,
    providerRepositoryId,
  )

  if (!visible || !reviewable) return null

  const name = provider
    ? `${provider.organization}/${provider.name}`
    : providerRepositoryId

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Code review')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Review pull requests to the connected repository and comment on them. The setting applies to the whole repository.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="rounded-lg border border-border bg-background divide-y divide-border">
          <div className="flex items-center gap-3 px-4 py-3">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
              <GitPullRequest className="h-4 w-4 text-muted-foreground" />
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-[13px] font-medium text-foreground">
                {t('Review pull requests')}
              </p>
              <p className="truncate text-[12px] text-muted-foreground">
                {t(
                  'Each pull request is reviewed when it is opened or gets new commits.',
                )}
              </p>
            </div>
            <ReviewToggle
              projectId={projectId}
              installationId={installationId}
              providerRepositoryId={providerRepositoryId}
              repository={repository}
              name={name}
              disabled={!canWrite || isLoading}
            />
          </div>
          {repository && (
            <div className="flex items-center gap-3 px-4 py-3">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
                <Gauge className="h-4 w-4 text-muted-foreground" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-[13px] font-medium text-foreground">
                  {t('Review strength')}
                </p>
                <p className="text-[12px] text-muted-foreground">
                  {t(
                    'How hard pull requests are reviewed, and what each review costs.',
                  )}
                </p>
              </div>
              <StrengthSelect
                projectId={projectId}
                repository={repository}
                name={name}
                disabled={!canWrite}
              />
            </div>
          )}
        </div>
      </div>
      {showReviewsLink && (
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            asChild
          >
            {resource.type === 'function' ? (
              <Link
                to="/projects/$projectId/functions/$functionId/reviews"
                params={{ projectId, functionId: resource.id }}
              >
                {t('View reviews')}
              </Link>
            ) : (
              <Link
                to="/projects/$projectId/sites/$siteId/reviews"
                params={{ projectId, siteId: resource.id }}
              >
                {t('View reviews')}
              </Link>
            )}
          </Button>
        </div>
      )}
    </div>
  )
}
