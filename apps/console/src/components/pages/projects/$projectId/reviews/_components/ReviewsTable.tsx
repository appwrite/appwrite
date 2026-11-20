import { useEffect, useState } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { GitPullRequest } from 'lucide-react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import type { Review } from '@/lib/appwrite/reviews'
import { isReviewRunning } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { ReviewActionsMenu, ReviewContextMenu } from './ReviewActions'
import {
  ReviewAuthorLink,
  ReviewStatusBadge,
  ReviewTierBadge,
  ReviewVerdictBadge,
  reviewDuration,
  useReviewTriggerLabel,
} from './ReviewBadges'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/** Re-renders every second while `active`, for durations that are still running. */
function useNow(active: boolean) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    if (!active) return
    setNow(Date.now())
    const interval = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(interval)
  }, [active])
  return now
}

export function ReviewsTable({
  projectId,
  reviews,
}: {
  projectId: string
  reviews: Review[]
}) {
  const t = useT()
  const navigate = useNavigate()
  const triggerLabel = useReviewTriggerLabel()
  const now = useNow(reviews.some(isReviewRunning))

  return (
    <div className="rounded-lg border border-border bg-card overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>{t('Pull request')}</TableHead>
            <TableHead className={`${HEAD_CLASS} hidden lg:table-cell`}>
              {t('Author')}
            </TableHead>
            <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Verdict')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Confidence')}</TableHead>
            <TableHead className={`${HEAD_CLASS} hidden md:table-cell`}>
              {t('Duration')}
            </TableHead>
            <TableHead className={`${HEAD_CLASS} text-end`}>
              {t('Created')}
            </TableHead>
            <TableHead className={`${HEAD_CLASS} w-[56px]`} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {reviews.map((review) => {
            const duration = reviewDuration(review, now)
            return (
              <ReviewContextMenu
                key={review.$id}
                projectId={projectId}
                review={review}
              >
                <TableRow
                  className="cursor-pointer transition-colors border-b border-border/50 hover:bg-muted/30"
                  onClick={(event) => {
                    const target = event.target as HTMLElement
                    if (target.closest('a, button, [role="menu"]')) return
                    navigate({
                      to: '/projects/$projectId/reviews/$reviewId',
                      params: { projectId, reviewId: review.$id },
                    })
                  }}
                >
                  <TableCell className="px-4 py-3 max-w-[380px]">
                    <Link
                      to="/projects/$projectId/reviews/$reviewId"
                      params={{ projectId, reviewId: review.$id }}
                      className="flex min-w-0 items-start gap-2"
                    >
                      <GitPullRequest className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                      <span className="min-w-0">
                        <span className="flex min-w-0 items-center gap-1.5">
                          <span className="truncate text-[13px] font-medium text-foreground">
                            {review.providerRepositoryOwner}/
                            {review.providerRepositoryName}
                          </span>
                          <span className="shrink-0 text-[13px] text-muted-foreground">
                            #{review.providerPullRequestId}
                          </span>
                        </span>
                        <span className="mt-0.5 flex min-w-0 items-center gap-1.5 text-[12px] text-muted-foreground">
                          <span className="truncate">
                            {triggerLabel(review.trigger)}
                          </span>
                          {review.providerCommitHash && (
                            <>
                              <span aria-hidden>·</span>
                              <span className="shrink-0 font-mono">
                                {review.providerCommitHash.slice(0, 7)}
                              </span>
                            </>
                          )}
                        </span>
                      </span>
                    </Link>
                  </TableCell>
                  <TableCell className="px-4 py-3 hidden lg:table-cell">
                    <ReviewAuthorLink
                      author={review.providerPullRequestAuthor}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <ReviewStatusBadge status={review.status} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <ReviewVerdictBadge verdict={review.verdict} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <ReviewTierBadge tier={review.tier} />
                  </TableCell>
                  <TableCell className="px-4 py-3 hidden md:table-cell">
                    <span className="whitespace-nowrap font-mono text-[12px] tabular-nums text-muted-foreground">
                      {duration || '-'}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    {review.$createdAt && (
                      <DateTooltip
                        date={review.$createdAt}
                        className="whitespace-nowrap text-[12px] text-muted-foreground font-mono"
                      />
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    <ReviewActionsMenu projectId={projectId} review={review} />
                  </TableCell>
                </TableRow>
              </ReviewContextMenu>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}
