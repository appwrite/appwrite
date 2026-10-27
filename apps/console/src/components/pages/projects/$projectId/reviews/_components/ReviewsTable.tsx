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
import { useT } from '@/lib/i18n/translate'
import {
  ReviewStatusBadge,
  ReviewTierBadge,
  ReviewVerdictBadge,
  useReviewTriggerLabel,
} from './ReviewBadges'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

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

  return (
    <div className="rounded-lg border border-border bg-card overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>{t('Pull request')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Verdict')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Confidence')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Trigger')}</TableHead>
            <TableHead className={`${HEAD_CLASS} text-end`}>
              {t('Created')}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {reviews.map((review) => (
            <TableRow
              key={review.$id}
              className="cursor-pointer transition-colors border-b border-border/50 hover:bg-muted/30"
              onClick={(event) => {
                if ((event.target as HTMLElement).closest('a')) return
                navigate({
                  to: '/projects/$projectId/reviews/$reviewId',
                  params: { projectId, reviewId: review.$id },
                })
              }}
            >
              <TableCell className="px-4 py-3">
                <Link
                  to="/projects/$projectId/reviews/$reviewId"
                  params={{ projectId, reviewId: review.$id }}
                  className="flex min-w-0 items-center gap-2"
                >
                  <GitPullRequest className="h-4 w-4 shrink-0 text-muted-foreground" />
                  <span className="truncate text-[13px] font-medium text-foreground">
                    {review.providerRepositoryOwner}/
                    {review.providerRepositoryName}
                  </span>
                  <span className="shrink-0 text-[13px] text-muted-foreground">
                    #{review.providerPullRequestId}
                  </span>
                  <span className="shrink-0 font-mono text-[12px] text-muted-foreground">
                    {review.providerCommitHash.slice(0, 7)}
                  </span>
                </Link>
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
              <TableCell className="px-4 py-3">
                <span className="text-[13px] text-muted-foreground">
                  {triggerLabel(review.trigger)}
                </span>
              </TableCell>
              <TableCell className="px-4 py-3 text-end">
                <DateTooltip
                  date={review.$createdAt}
                  className="text-[12px] text-muted-foreground font-mono"
                />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}
