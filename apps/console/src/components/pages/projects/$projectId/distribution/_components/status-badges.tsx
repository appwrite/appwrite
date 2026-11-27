import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'

type BadgeVariant = React.ComponentProps<typeof Badge>['variant']

interface StatusAppearance {
  label: string
  variant: BadgeVariant
}

const BUILD_STATUS: Record<string, StatusAppearance> = {
  waiting: { label: 'Waiting', variant: 'pending' },
  processing: { label: 'Processing', variant: 'processing' },
  building: { label: 'Building', variant: 'processing' },
  signing: { label: 'Signing', variant: 'processing' },
  ready: { label: 'Ready', variant: 'success' },
  failed: { label: 'Failed', variant: 'error' },
  canceled: { label: 'Canceled', variant: 'inactive' },
}

const CONNECTION_STATUS: Record<string, StatusAppearance> = {
  active: { label: 'Active', variant: 'success' },
  invalid: { label: 'Invalid', variant: 'error' },
}

const SIGNING_IDENTITY_STATUS: Record<string, StatusAppearance> = {
  active: { label: 'Active', variant: 'success' },
  expiring: { label: 'Expiring', variant: 'warning' },
  expired: { label: 'Expired', variant: 'error' },
  revoked: { label: 'Revoked', variant: 'inactive' },
}

function fallback(status: string): StatusAppearance {
  const label = status
    ? status.charAt(0).toUpperCase() + status.slice(1)
    : 'Unknown'
  return { label, variant: 'info' }
}

function StatusBadge({
  status,
  appearances,
}: {
  status: string
  appearances: Record<string, StatusAppearance>
}) {
  const t = useT()
  const { label, variant } = appearances[status] ?? fallback(status)
  return (
    <Badge variant={variant} className="text-[10px] shrink-0">
      {t(label)}
    </Badge>
  )
}

export function BuildStatusBadge({ status }: { status: string }) {
  return <StatusBadge status={status} appearances={BUILD_STATUS} />
}

export function ConnectionStatusBadge({ status }: { status: string }) {
  return <StatusBadge status={status} appearances={CONNECTION_STATUS} />
}

export function SigningIdentityStatusBadge({ status }: { status: string }) {
  return <StatusBadge status={status} appearances={SIGNING_IDENTITY_STATUS} />
}
