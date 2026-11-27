import { Bot, UserRound } from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { formatMs } from '@/lib/review-trace'
import type {
  Review,
  ReviewFindingSeverity,
  ReviewStatus,
  ReviewTrigger,
  ReviewVerdict,
} from '@/lib/appwrite/reviews'

const STATUS_LABELS: Record<ReviewStatus, string> = {
  queued: 'Queued',
  processing: 'Reviewing',
  completed: 'Completed',
  skipped: 'Skipped',
  failed: 'Failed',
}

const STATUS_VARIANTS = {
  queued: 'pending',
  processing: 'processing',
  completed: 'success',
  skipped: 'inactive',
  failed: 'error',
} as const satisfies Record<ReviewStatus, string>

export function ReviewStatusBadge({ status }: { status: ReviewStatus }) {
  const t = useT()
  return (
    <Badge
      variant={STATUS_VARIANTS[status] ?? 'info'}
      className="text-[10px] shrink-0"
    >
      {t(STATUS_LABELS[status] ?? status)}
    </Badge>
  )
}

const VERDICT_LABELS: Record<ReviewVerdict, string> = {
  approve: 'Approved',
  request_changes: 'Changes requested',
  comment: 'Commented',
}

const VERDICT_VARIANTS = {
  approve: 'success',
  request_changes: 'error',
  comment: 'info',
} as const satisfies Record<ReviewVerdict, string>

/** The verdict the review posted on GitHub; nothing until it completes. */
export function ReviewVerdictBadge({
  verdict,
}: {
  verdict: ReviewVerdict | ''
}) {
  const t = useT()
  if (!verdict) {
    return <span className="text-[13px] text-muted-foreground">-</span>
  }
  return (
    <Badge
      variant={VERDICT_VARIANTS[verdict] ?? 'info'}
      className="text-[10px] shrink-0"
    >
      {t(VERDICT_LABELS[verdict] ?? verdict)}
    </Badge>
  )
}

/** Merge confidence from S (best) to F; the colors follow the grade. */
export function ReviewTierBadge({ tier }: { tier: string }) {
  if (!tier) {
    return <span className="text-[13px] text-muted-foreground">-</span>
  }
  const variant =
    tier === 'S' || tier === 'A'
      ? 'success'
      : tier === 'B' || tier === 'C'
        ? 'warning'
        : 'error'
  return (
    <Badge
      variant={variant}
      className="h-5 w-5 shrink-0 justify-center rounded-md p-0 font-mono text-[11px] font-semibold"
    >
      {tier}
    </Badge>
  )
}

const TRIGGER_LABELS: Record<ReviewTrigger, string> = {
  opened: 'Pull request opened',
  synchronize: 'New commits',
  ready_for_review: 'Ready for review',
  retry: 'Retry',
  mention: 'Requested in a comment',
}

export function useReviewTriggerLabel() {
  const t = useT()
  return (trigger: ReviewTrigger) => t(TRIGGER_LABELS[trigger] ?? trigger)
}

export const SEVERITY_LABELS: Record<ReviewFindingSeverity, string> = {
  critical: 'Critical',
  major: 'Major',
  minor: 'Minor',
  info: 'Info',
}

const SEVERITY_VARIANTS = {
  critical: 'error',
  major: 'warning',
  minor: 'info',
  info: 'inactive',
} as const satisfies Record<ReviewFindingSeverity, string>

/** Most severe first, for ordering findings. */
export const SEVERITY_ORDER: ReviewFindingSeverity[] = [
  'critical',
  'major',
  'minor',
  'info',
]

export function FindingSeverityBadge({
  severity,
}: {
  severity: ReviewFindingSeverity
}) {
  const t = useT()
  return (
    <Badge
      variant={SEVERITY_VARIANTS[severity] ?? 'info'}
      className="text-[10px] shrink-0"
    >
      {t(SEVERITY_LABELS[severity] ?? severity)}
    </Badge>
  )
}

const SEVERITY_DOTS = {
  critical: 'bg-red-500',
  major: 'bg-amber-500',
  minor: 'bg-muted-foreground/40',
  info: 'bg-muted-foreground/40',
} as const satisfies Record<ReviewFindingSeverity, string>

/**
 * How many findings a review posted, with a dot in the color of the worst.
 * Nothing until the review completes; an API that predates the count leaves
 * it out.
 */
export function ReviewFindingsCount({
  review,
}: {
  review: Pick<Review, 'status' | 'findingsTotal' | 'severity'>
}) {
  const t = useT()
  if (review.status !== 'completed' || review.findingsTotal === undefined) {
    return <span className="text-[13px] text-muted-foreground">-</span>
  }
  if (review.findingsTotal === 0) {
    return (
      <span className="text-[13px] tabular-nums text-muted-foreground">0</span>
    )
  }
  const label = review.severity
    ? t('Most severe: {severity}').replace(
        '{severity}',
        t(SEVERITY_LABELS[review.severity]),
      )
    : undefined
  return (
    <span
      className="inline-flex items-center gap-1.5 text-[13px] tabular-nums text-foreground"
      title={label}
    >
      {review.severity && (
        <span
          className={cn(
            'h-2 w-2 shrink-0 rounded-full',
            SEVERITY_DOTS[review.severity],
          )}
          aria-label={label}
          role="img"
        />
      )}
      {review.findingsTotal.toLocaleString()}
    </span>
  )
}

/** GitHub suffixes an app's login, such as `renovate[bot]`. */
const BOT_SUFFIX = '[bot]'

/** Where an author's GitHub profile lives: an app's page for a bot. */
export function authorUrl(author: string): string {
  return author.endsWith(BOT_SUFFIX)
    ? `https://github.com/apps/${encodeURIComponent(author.slice(0, -BOT_SUFFIX.length))}`
    : `https://github.com/${encodeURIComponent(author)}`
}

/**
 * Who opened the pull request, with their GitHub avatar, linking to their
 * profile. A bot shows its app name and a bot mark; a review queued before
 * authors were kept has none, and says so.
 */
export function ReviewAuthorLink({ author }: { author: string }) {
  const t = useT()
  if (!author) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="flex min-w-0 items-center gap-2">
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-border bg-muted/40">
              <UserRound className="h-3.5 w-3.5 text-muted-foreground" />
            </span>
            <span className="truncate text-[13px] text-muted-foreground">
              {t('Unknown author')}
            </span>
          </span>
        </TooltipTrigger>
        <TooltipContent>
          {t('Reviews queued before authors were recorded')}
        </TooltipContent>
      </Tooltip>
    )
  }
  const bot = author.endsWith(BOT_SUFFIX)
  const name = bot ? author.slice(0, -BOT_SUFFIX.length) : author
  return (
    <a
      href={authorUrl(author)}
      target="_blank"
      rel="noreferrer"
      className="group flex min-w-0 items-center gap-2"
    >
      <Avatar className="h-6 w-6 shrink-0">
        {!bot && (
          <AvatarImage
            src={`https://github.com/${encodeURIComponent(author)}.png?size=48`}
            alt=""
          />
        )}
        <AvatarFallback className="text-[10px]">
          {bot ? (
            <Bot className="h-3.5 w-3.5 text-muted-foreground" />
          ) : (
            name.slice(0, 2).toUpperCase()
          )}
        </AvatarFallback>
      </Avatar>
      <span className="truncate text-[13px] font-medium text-foreground group-hover:underline">
        {bot ? name : `@${name}`}
      </span>
      {bot && (
        <Badge variant="inactive" className="shrink-0 text-[10px]">
          {t('Bot')}
        </Badge>
      )}
    </a>
  )
}

/**
 * How long a review ran: from start to end, or to `now` while it runs. Empty
 * until it starts, and for a review that ended without starting.
 */
export function reviewDuration(
  review: Pick<Review, 'startedAt' | 'completedAt'>,
  now: number,
): string {
  if (!review.startedAt) return ''
  const start = Date.parse(review.startedAt)
  const end = review.completedAt ? Date.parse(review.completedAt) : now
  if (Number.isNaN(start) || Number.isNaN(end)) return ''
  return formatReviewTime(Math.max(0, end - start))
}

/** A review's run time: `840ms`, `12.4s`, `2m 14s`, or `1h 5m` past an hour. */
export function formatReviewTime(ms: number): string {
  if (ms < 60 * 60 * 1000) return formatMs(ms)
  const minutes = Math.floor(ms / 60_000)
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`
}
