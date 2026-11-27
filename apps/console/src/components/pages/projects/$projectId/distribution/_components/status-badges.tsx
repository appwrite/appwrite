import { Badge } from '@/components/ui/badge'
import {
  ARTIFACT_STATUS,
  BUILD_STATUS,
  CHECK_SEVERITY,
  CONNECTION_STATUS,
  RELEASE_STATUS,
  SIGNING_IDENTITY_STATUS,
  SUBMISSION_STATUS,
  statusAppearance,
} from '@/lib/distribution/status'
import type { StatusAppearance } from '@/lib/distribution/status'
import { useT } from '@/lib/i18n/translate'

function StatusBadge({
  status,
  appearances,
}: {
  status: string
  appearances: Record<string, StatusAppearance>
}) {
  const t = useT()
  const { label, variant } = statusAppearance(status, appearances)
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

export function ArtifactStatusBadge({ status }: { status: string }) {
  return <StatusBadge status={status} appearances={ARTIFACT_STATUS} />
}

export function SubmissionStatusBadge({ status }: { status: string }) {
  return <StatusBadge status={status} appearances={SUBMISSION_STATUS} />
}

export function ReleaseStatusBadge({ status }: { status: string }) {
  return <StatusBadge status={status} appearances={RELEASE_STATUS} />
}

export function CheckSeverityBadge({ status }: { status: string }) {
  return <StatusBadge status={status} appearances={CHECK_SEVERITY} />
}
