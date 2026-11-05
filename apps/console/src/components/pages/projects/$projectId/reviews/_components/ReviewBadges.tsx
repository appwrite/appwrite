import { Loader2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import type {
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
    <div className="flex items-center gap-2">
      {status === 'processing' && (
        <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
      )}
      <Badge
        variant={STATUS_VARIANTS[status] ?? 'info'}
        className="text-[10px] shrink-0"
      >
        {t(STATUS_LABELS[status] ?? status)}
      </Badge>
    </div>
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
    <Badge variant={variant} className="text-[10px] shrink-0 font-mono">
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

const SEVERITY_LABELS: Record<ReviewFindingSeverity, string> = {
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
