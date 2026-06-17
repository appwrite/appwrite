import { Badge } from '@/components/ui/badge'
import type {
  DistributionBuildStatus,
  DistributionSubmissionStatus,
} from '@/lib/react-query/hooks'

type BadgeVariant = React.ComponentProps<typeof Badge>['variant']

const BUILD_STATUS: Record<
  DistributionBuildStatus,
  { label: string; variant: BadgeVariant }
> = {
  building: { label: 'Building', variant: 'processing' },
  ready: { label: 'Ready', variant: 'success' },
  failed: { label: 'Failed', variant: 'error' },
  canceled: { label: 'Canceled', variant: 'inactive' },
}

const SUBMISSION_STATUS: Record<
  DistributionSubmissionStatus,
  { label: string; variant: BadgeVariant }
> = {
  queued: { label: 'Queued', variant: 'pending' },
  processing: { label: 'Processing', variant: 'processing' },
  in_review: { label: 'In review', variant: 'info' },
  approved: { label: 'Approved', variant: 'success' },
  published: { label: 'Published', variant: 'success' },
  rejected: { label: 'Rejected', variant: 'error' },
  failed: { label: 'Failed', variant: 'error' },
}

export function BuildStatusBadge({
  status,
}: {
  status: DistributionBuildStatus
}) {
  const { label, variant } = BUILD_STATUS[status]
  return (
    <Badge variant={variant} className="text-[10px] shrink-0">
      {label}
    </Badge>
  )
}

export function SubmissionStatusBadge({
  status,
}: {
  status: DistributionSubmissionStatus
}) {
  const { label, variant } = SUBMISSION_STATUS[status]
  return (
    <Badge variant={variant} className="text-[10px] shrink-0">
      {label}
    </Badge>
  )
}
