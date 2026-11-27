import { Badge } from '@/components/ui/badge'
import type { SandboxStatus } from '@/lib/appwrite/sandboxes'
import { useT } from '@/lib/i18n/translate'

const VARIANTS = {
  creating: 'warning',
  ready: 'success',
  failed: 'error',
  deleting: 'info',
} as const

export function SandboxStatusBadge({ status }: { status: SandboxStatus }) {
  const t = useT()
  const labels: Record<SandboxStatus, string> = {
    creating: t('Creating'),
    ready: t('Ready'),
    failed: t('Failed'),
    deleting: t('Deleting'),
  }

  return (
    <Badge variant={VARIANTS[status] ?? 'info'} className="text-[11px]">
      {labels[status] ?? status}
    </Badge>
  )
}
