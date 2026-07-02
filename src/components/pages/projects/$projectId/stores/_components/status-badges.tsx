import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'

type BadgeVariant = React.ComponentProps<typeof Badge>['variant']

const BUILD_STATUS: Record<string, { label: string; variant: BadgeVariant }> = {
  building: { label: 'Building', variant: 'processing' },
  ready: { label: 'Ready', variant: 'success' },
  failed: { label: 'Failed', variant: 'error' },
  canceled: { label: 'Canceled', variant: 'inactive' },
}

const SUBMISSION_STATUS: Record<
  string,
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

function fallback(status: string): { label: string; variant: BadgeVariant } {
  const label = status
    ? status.charAt(0).toUpperCase() + status.slice(1)
    : 'Unknown'
  return { label, variant: 'default' }
}

export function BuildStatusBadge({ status }: { status: string }) {
  const t = useT()
  const { label, variant } = BUILD_STATUS[status] ?? fallback(status)
  return (
    <Badge variant={variant} className="text-[10px] shrink-0">
      {t(label)}
    </Badge>
  )
}

export function SubmissionStatusBadge({ status }: { status: string }) {
  const t = useT()
  const { label, variant } = SUBMISSION_STATUS[status] ?? fallback(status)
  return (
    <Badge variant={variant} className="text-[10px] shrink-0">
      {t(label)}
    </Badge>
  )
}
