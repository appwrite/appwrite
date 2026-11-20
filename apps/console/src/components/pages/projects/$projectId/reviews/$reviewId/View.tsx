import { useEffect, useMemo, useState } from 'react'
import {
  useCanGoBack,
  useNavigate,
  useParams,
  useRouter,
} from '@tanstack/react-router'
import {
  AlertCircle,
  ArrowLeft,
  ExternalLink,
  FileCode,
  MoreHorizontal,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { MarkdownContent } from '@/components/global/shared/MarkdownContent'
import type {
  Review,
  ReviewFinding,
  ReviewFindingSeverity,
  ReviewStrength,
} from '@/lib/appwrite/reviews'
import { isReviewRunning, useReview } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { ServiceHeader } from '../../shared/ServiceHeader'
import { Trace } from '../_components/Trace'
import {
  ReviewActionsMenu,
  reviewGitHubUrls,
} from '../_components/ReviewActions'
import {
  FindingSeverityBadge,
  ReviewAuthorLink,
  ReviewStatusBadge,
  ReviewTierBadge,
  ReviewVerdictBadge,
  SEVERITY_LABELS,
  SEVERITY_ORDER,
  reviewDuration,
  useReviewTriggerLabel,
} from '../_components/ReviewBadges'

const STRENGTH_LABELS: Record<ReviewStrength, string> = {
  basic: 'Basic',
  normal: 'Normal',
  expert: 'Expert',
}

type ViewProps = {
  initialReview?: Review
}

export function View({ initialReview }: ViewProps = {}) {
  const t = useT()
  const navigate = useNavigate()
  const router = useRouter()
  const canGoBack = useCanGoBack()
  const { projectId, reviewId } = useParams({ strict: false }) as {
    projectId: string
    reviewId: string
  }
  const { review: reviewFromHook, isLoading } = useReview(projectId, reviewId)
  const review = reviewFromHook ?? initialReview ?? null
  const triggerLabel = useReviewTriggerLabel()

  // A running review's duration counts up.
  const running = review ? isReviewRunning(review) : false
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    if (!running) return
    const interval = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(interval)
  }, [running])

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
  const [severity, setSeverity] = useState<ReviewFindingSeverity | null>(null)
  const shownFindings = severity
    ? findings.filter((finding) => finding.severity === severity)
    : findings

  // Back to the list as it was left (page and filters), or to the list itself
  // when the review was opened from a link.
  const back = () => {
    if (canGoBack) {
      router.history.back()
      return
    }
    navigate({ to: '/projects/$projectId/reviews', params: { projectId } })
  }

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
          action={
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() =>
                navigate({
                  to: '/projects/$projectId/reviews',
                  params: { projectId },
                })
              }
            >
              {t('Back to reviews')}
            </Button>
          }
        />
      </div>
    )
  }

  const urls = reviewGitHubUrls(review)
  const duration = reviewDuration(review, now)
  const compareUrl =
    review.providerBaseCommitHash && review.providerCommitHash
      ? `${urls.repository}/compare/${review.providerBaseCommitHash}...${review.providerCommitHash}`
      : undefined

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
              onClick={back}
            >
              <ArrowLeft className="h-4 w-4 rtl:rotate-180" />
            </Button>
            <span className="min-w-0 truncate">
              {review.providerRepositoryOwner}/{review.providerRepositoryName}{' '}
              <span className="text-muted-foreground">
                #{review.providerPullRequestId}
              </span>
            </span>
            <CopyableId
              id={review.$id}
              size="xs"
              className="hidden shrink-0 sm:inline-flex"
            />
          </div>
        }
        titleRightContent={
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              className="h-8 text-[13px]"
              asChild
            >
              <a
                href={urls.review}
                target="_blank"
                rel="noreferrer"
                aria-label={t('Open on GitHub')}
              >
                <ExternalLink className="h-3.5 w-3.5 sm:me-1.5" />
                <span className="hidden sm:inline">{t('Open on GitHub')}</span>
              </a>
            </Button>
            <ReviewActionsMenu
              projectId={projectId}
              review={review}
              trigger={
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 w-8 p-0"
                  aria-label={t('More actions')}
                >
                  <MoreHorizontal className="h-4 w-4" />
                </Button>
              }
            />
          </div>
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="grid grid-cols-2 gap-x-6 gap-y-5 px-6 py-5 md:grid-cols-4">
              <Detail label={t('Status')}>
                <ReviewStatusBadge status={review.status} />
              </Detail>
              <Detail label={t('Verdict')}>
                <ReviewVerdictBadge verdict={review.verdict} />
              </Detail>
              <Detail label={t('Confidence')}>
                <ReviewTierBadge tier={review.tier} />
              </Detail>
              <Detail label={t('Author')}>
                <ReviewAuthorLink author={review.providerPullRequestAuthor} />
              </Detail>
              <Detail label={t('Trigger')}>
                <span className="text-[13px] text-foreground">
                  {triggerLabel(review.trigger)}
                </span>
              </Detail>
              <Detail label={t('Strength')}>
                <span className="text-[13px] text-foreground">
                  {review.strength
                    ? t(STRENGTH_LABELS[review.strength] ?? review.strength)
                    : '-'}
                </span>
              </Detail>
              <Detail label={t('Commit')}>
                {review.providerCommitHash ? (
                  <span className="flex min-w-0 items-center gap-1 font-mono text-[13px]">
                    {review.providerBaseCommitHash && (
                      <>
                        <span className="text-muted-foreground">
                          {review.providerBaseCommitHash.slice(0, 7)}
                        </span>
                        <span className="text-muted-foreground">...</span>
                      </>
                    )}
                    <a
                      href={compareUrl ?? urls.commit}
                      target="_blank"
                      rel="noreferrer"
                      className="text-foreground hover:underline"
                      title={
                        compareUrl
                          ? t('Compare on GitHub')
                          : t('Open commit on GitHub')
                      }
                    >
                      {review.providerCommitHash.slice(0, 7)}
                    </a>
                  </span>
                ) : (
                  <Empty />
                )}
              </Detail>
              <Detail label={t('Duration')}>
                {duration ? (
                  <span className="font-mono text-[13px] tabular-nums text-foreground">
                    {duration}
                  </span>
                ) : (
                  <Empty />
                )}
              </Detail>
              <Detail label={t('Created')}>
                <DateValue date={review.$createdAt} />
              </Detail>
              <Detail label={t('Started')}>
                <DateValue date={review.startedAt} />
              </Detail>
              <Detail label={t('Finished')}>
                <DateValue date={review.completedAt} />
              </Detail>
            </div>
            {review.reason && (
              <>
                <div className="border-t border-border" />
                <div
                  className={cn(
                    'flex items-start gap-3 px-6 py-4',
                    review.status === 'failed' && 'bg-destructive/5',
                  )}
                >
                  <AlertCircle
                    className={cn(
                      'mt-0.5 h-4 w-4 shrink-0',
                      review.status === 'failed'
                        ? 'text-destructive'
                        : 'text-muted-foreground',
                    )}
                  />
                  <div className="min-w-0">
                    <p className="text-[13px] font-medium text-foreground">
                      {review.status === 'skipped'
                        ? t('Skipped because')
                        : t('Failed because')}
                    </p>
                    <p className="mt-0.5 text-[13px] text-muted-foreground">
                      {review.reason}
                    </p>
                  </div>
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
              <div className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Findings')}{' '}
                  <span className="text-muted-foreground">
                    {findings.length}
                  </span>
                </h3>
                {findings.length > 0 && (
                  <div
                    className="flex flex-wrap items-center gap-1.5"
                    role="group"
                    aria-label={t('Filter findings by severity')}
                  >
                    <SeverityChip
                      active={severity === null}
                      onClick={() => setSeverity(null)}
                      label={t('All')}
                      count={findings.length}
                    />
                    {SEVERITY_ORDER.map((level) => {
                      const count = findings.filter(
                        (finding) => finding.severity === level,
                      ).length
                      if (count === 0) return null
                      return (
                        <SeverityChip
                          key={level}
                          active={severity === level}
                          onClick={() =>
                            setSeverity(severity === level ? null : level)
                          }
                          label={t(SEVERITY_LABELS[level])}
                          count={count}
                        />
                      )
                    })}
                  </div>
                )}
              </div>
              <div className="border-t border-border" />
              {findings.length === 0 ? (
                <p className="px-6 py-6 text-[13px] text-muted-foreground">
                  {t('The review found nothing to flag.')}
                </p>
              ) : (
                <ul className="divide-y divide-border">
                  {shownFindings.map((finding) => (
                    <Finding
                      key={finding.$id}
                      finding={finding}
                      commitUrl={`${urls.repository}/blob/${review.providerCommitHash}`}
                      pullRequestUrl={urls.pullRequest}
                    />
                  ))}
                </ul>
              )}
            </div>
          )}

          <Trace
            projectId={projectId}
            reviewId={review.$id}
            running={running}
          />
        </div>
      </div>
    </div>
  )
}

function Empty() {
  return <span className="text-[13px] text-muted-foreground">-</span>
}

function DateValue({ date }: { date: string }) {
  if (!date) return <Empty />
  return <DateTooltip date={date} className="text-[13px] text-foreground" />
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
      <div className="mt-1.5 flex min-h-6 items-center">{children}</div>
    </div>
  )
}

function SeverityChip({
  active,
  onClick,
  label,
  count,
}: {
  active: boolean
  onClick: () => void
  label: string
  count: number
}) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={cn(
        'inline-flex h-7 items-center gap-1.5 rounded-md border px-2.5 text-[12px] font-medium transition-colors',
        active
          ? 'border-border bg-secondary text-secondary-foreground'
          : 'border-transparent text-muted-foreground hover:text-foreground',
      )}
    >
      {label}
      <span className="tabular-nums text-muted-foreground">{count}</span>
    </button>
  )
}

function Finding({
  finding,
  commitUrl,
  pullRequestUrl,
}: {
  finding: ReviewFinding
  commitUrl: string
  pullRequestUrl: string
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
          <span className="rounded border border-border px-1.5 text-[11px] leading-5 text-muted-foreground">
            {finding.category}
          </span>
        )}
        <span className="text-[13px] font-medium text-foreground">
          {finding.title}
        </span>
      </div>
      <div className="mt-1.5 flex flex-wrap items-center gap-3">
        <a
          href={`${commitUrl}/${finding.path}#${lines}`}
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
        {finding.providerCommentId && (
          <a
            href={`${pullRequestUrl}#discussion_r${finding.providerCommentId}`}
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
