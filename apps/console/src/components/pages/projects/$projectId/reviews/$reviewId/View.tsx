import { useMemo } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { AlertCircle, ArrowLeft, ExternalLink, FileCode } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { MarkdownContent } from '@/components/global/shared/MarkdownContent'
import type { Review, ReviewFinding } from '@/lib/appwrite/reviews'
import {
  isReviewRunning,
  useReview,
  useReviewProvider,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import {
  getKnownVcsProvider,
  getProviderPullRequestUrl,
  getProviderRepositoryUrl,
} from '@/lib/vcs/providers'
import { ServiceHeader } from '../../shared/ServiceHeader'
import { Trace } from '../_components/Trace'
import {
  FindingSeverityBadge,
  ReviewStatusBadge,
  ReviewTierBadge,
  ReviewVerdictBadge,
  SEVERITY_ORDER,
  useReviewTriggerLabel,
} from '../_components/ReviewBadges'

type ViewProps = {
  initialReview?: Review
}

export function View({ initialReview }: ViewProps = {}) {
  const t = useT()
  const navigate = useNavigate()
  const { projectId, reviewId } = useParams({ strict: false }) as {
    projectId: string
    reviewId: string
  }
  const { review: reviewFromHook, isLoading } = useReview(projectId, reviewId)
  const review = reviewFromHook ?? initialReview ?? null
  const triggerLabel = useReviewTriggerLabel()
  const provider = useReviewProvider(projectId, review?.repositoryId)

  const findings = useMemo(
    () =>
      [...(review?.findings ?? [])].sort(
        (a, b) =>
          SEVERITY_ORDER.indexOf(a.severity) -
            SEVERITY_ORDER.indexOf(b.severity) ||
          a.path.localeCompare(b.path) ||
          a.startLine - b.startLine,
      ),
    [review?.findings],
  )

  if (!review) {
    if (isLoading) return null
    return (
      <div className="flex items-center justify-center p-6 py-16">
        <EmptyState
          icon={AlertCircle}
          title={t('Review not found')}
          description={t(
            'This review may have been deleted or the link is incorrect.',
          )}
          variant="card"
          iconSize="md"
        />
      </div>
    )
  }

  const links = getReviewLinks(provider, review)
  const providerLabel = getKnownVcsProvider(provider)?.label

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 flex-1 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              aria-label={t('Back to reviews')}
              onClick={() =>
                navigate({
                  to: '/projects/$projectId/reviews',
                  params: { projectId },
                })
              }
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span className="min-w-0 truncate">
              {review.providerRepositoryOwner}/{review.providerRepositoryName}{' '}
              <span className="text-muted-foreground">
                #{review.providerPullRequestId}
              </span>
            </span>
            <CopyableId id={review.$id} size="xs" className="shrink-0" />
          </div>
        }
        titleRightContent={
          links.pullRequest && providerLabel ? (
            <Button
              variant="outline"
              size="sm"
              className="h-8 text-[13px]"
              asChild
            >
              <a
                href={links.review ?? links.pullRequest}
                target="_blank"
                rel="noreferrer"
              >
                <ExternalLink className="h-3.5 w-3.5 me-1.5" />
                {t(`Open on ${providerLabel}`)}
              </a>
            </Button>
          ) : undefined
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="grid grid-cols-2 gap-x-6 gap-y-4 px-6 py-4 md:grid-cols-4">
              <Detail label={t('Status')}>
                <ReviewStatusBadge status={review.status} />
              </Detail>
              <Detail label={t('Verdict')}>
                <ReviewVerdictBadge verdict={review.verdict} />
              </Detail>
              <Detail label={t('Confidence')}>
                <ReviewTierBadge tier={review.tier} />
              </Detail>
              <Detail label={t('Trigger')}>
                <span className="text-[13px] text-foreground">
                  {triggerLabel(review.trigger)}
                </span>
              </Detail>
              <Detail label={t('Commit')}>
                {review.providerCommitHash && links.commit ? (
                  <a
                    href={links.commit}
                    target="_blank"
                    rel="noreferrer"
                    className="font-mono text-[13px] text-foreground hover:underline"
                  >
                    {review.providerCommitHash.slice(0, 7)}
                  </a>
                ) : review.providerCommitHash ? (
                  <span className="font-mono text-[13px] text-foreground">
                    {review.providerCommitHash.slice(0, 7)}
                  </span>
                ) : (
                  <span className="text-[13px] text-muted-foreground">-</span>
                )}
              </Detail>
              <Detail label={t('Created')}>
                {review.$createdAt ? (
                  <DateTooltip
                    date={review.$createdAt}
                    className="text-[13px] text-foreground"
                  />
                ) : (
                  <span className="text-[13px] text-muted-foreground">-</span>
                )}
              </Detail>
              <Detail label={t('Started')}>
                {review.startedAt ? (
                  <DateTooltip
                    date={review.startedAt}
                    className="text-[13px] text-foreground"
                  />
                ) : (
                  <span className="text-[13px] text-muted-foreground">-</span>
                )}
              </Detail>
              <Detail label={t('Finished')}>
                {review.completedAt ? (
                  <DateTooltip
                    date={review.completedAt}
                    className="text-[13px] text-foreground"
                  />
                ) : (
                  <span className="text-[13px] text-muted-foreground">-</span>
                )}
              </Detail>
            </div>
            {review.reason && (
              <>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {review.status === 'skipped'
                      ? t('Skipped because')
                      : t('Failed because')}
                  </p>
                  <p className="mt-1 text-[13px] text-foreground">
                    {review.reason}
                  </p>
                </div>
              </>
            )}
          </div>

          {review.summary && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Summary')}
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4 text-[13px] text-foreground">
                <MarkdownContent content={review.summary} />
              </div>
            </div>
          )}

          {review.status === 'completed' && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Findings')}{' '}
                  <span className="text-muted-foreground">
                    {findings.length}
                  </span>
                </h3>
              </div>
              <div className="border-t border-border" />
              {findings.length === 0 ? (
                <p className="px-6 py-6 text-[13px] text-muted-foreground">
                  {t('The review found nothing to flag.')}
                </p>
              ) : (
                <ul className="divide-y divide-border">
                  {findings.map((finding) => (
                    <Finding
                      key={finding.$id}
                      finding={finding}
                      fileUrl={links.file}
                      commentUrl={links.comment}
                      lineAnchors={links.lineAnchors}
                    />
                  ))}
                </ul>
              )}
            </div>
          )}

          <Trace
            projectId={projectId}
            reviewId={review.$id}
            running={isReviewRunning(review)}
          />
        </div>
      </div>
    </div>
  )
}

function Detail({
  label,
  children,
}: {
  label: string
  children: React.ReactNode
}) {
  return (
    <div className="min-w-0">
      <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      <div className="mt-1.5">{children}</div>
    </div>
  )
}

function Finding({
  finding,
  fileUrl,
  commentUrl,
  lineAnchors,
}: {
  finding: ReviewFinding
  /** The files at the reviewed commit, or null where no link can be built. */
  fileUrl: string | null
  commentUrl: (commentId: string) => string | null
  /** Whether the provider's file pages take `#L1-L2` line anchors. */
  lineAnchors: boolean
}) {
  const t = useT()
  const lines =
    finding.startLine === finding.endLine
      ? `L${finding.startLine}`
      : `L${finding.startLine}-L${finding.endLine}`

  return (
    <li className="px-6 py-4">
      <div className="flex flex-wrap items-center gap-2">
        <FindingSeverityBadge severity={finding.severity} />
        {finding.category && (
          <span className="text-[12px] text-muted-foreground">
            {finding.category}
          </span>
        )}
        <span className="text-[13px] font-medium text-foreground">
          {finding.title}
        </span>
      </div>
      <div className="mt-1.5 flex flex-wrap items-center gap-3">
        <a
          href={
            fileUrl
              ? `${fileUrl}/${finding.path}${lineAnchors ? `#${lines}` : ''}`
              : undefined
          }
          target="_blank"
          rel="noreferrer"
          className="flex min-w-0 items-center gap-1.5 font-mono text-[12px] text-muted-foreground hover:text-foreground"
        >
          <FileCode className="h-3.5 w-3.5 shrink-0" />
          <span className="truncate">
            {finding.path}:{finding.startLine}
            {finding.endLine !== finding.startLine && `-${finding.endLine}`}
          </span>
        </a>
        {finding.providerCommentId && commentUrl(finding.providerCommentId) && (
          <a
            href={commentUrl(finding.providerCommentId)!}
            target="_blank"
            rel="noreferrer"
            className="text-[12px] text-muted-foreground hover:text-foreground hover:underline"
          >
            {t('View comment')}
          </a>
        )}
      </div>
      {finding.body && (
        <div className="mt-2 text-[13px] text-foreground">
          <MarkdownContent content={finding.body} />
        </div>
      )}
    </li>
  )
}

/**
 * Links to what a review is about on its provider. Each is null until the
 * provider is known, or where the provider has no such page: only GitHub has
 * anchors for a submitted review, a review comment and a line range.
 */
function getReviewLinks(provider: string, review: Review) {
  const location = {
    provider,
    organization: review.providerRepositoryOwner,
    repositoryName: review.providerRepositoryName,
  }
  const repositoryUrl = getProviderRepositoryUrl(location)
  const pullRequestUrl = getProviderPullRequestUrl({
    ...location,
    pullRequestId: review.providerPullRequestId,
  })
  const isGitHub = getKnownVcsProvider(provider)?.id === 'github'
  const commit = review.providerCommitHash

  return {
    pullRequest: pullRequestUrl,
    review:
      isGitHub && pullRequestUrl && review.providerReviewId
        ? `${pullRequestUrl}#pullrequestreview-${review.providerReviewId}`
        : null,
    commit: repositoryUrl && commit ? `${repositoryUrl}/commit/${commit}` : null,
    file: repositoryUrl && commit ? `${repositoryUrl}/blob/${commit}` : null,
    comment: (commentId: string) =>
      isGitHub && pullRequestUrl
        ? `${pullRequestUrl}#discussion_r${commentId}`
        : null,
    lineAnchors: isGitHub,
  }
}
