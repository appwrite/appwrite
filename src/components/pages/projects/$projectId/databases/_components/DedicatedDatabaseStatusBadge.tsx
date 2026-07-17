import { Badge } from '@/components/ui/badge'
import {
  dedicatedDatabaseStatusBadgeVariant,
  isDedicatedDatabaseReady,
} from '@/lib/databases/dedicated-database-status'
import { localizeResourceStatusLabel } from '@/lib/i18n/resource-status-labels'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type DedicatedDatabaseStatusBadgeProps = {
  status: string | null | undefined
  className?: string
  /** When true, hide the badge for ready / empty status. */
  onlyWhenNotReady?: boolean
}

export function DedicatedDatabaseStatusBadge({
  status,
  className,
  onlyWhenNotReady = false,
}: DedicatedDatabaseStatusBadgeProps) {
  const t = useT()
  if (!status?.trim()) return null
  if (onlyWhenNotReady && isDedicatedDatabaseReady(status)) return null

  return (
    <Badge
      variant={dedicatedDatabaseStatusBadgeVariant(status)}
      className={cn(
        'text-[10px] font-medium shrink-0 border px-2 py-0.5 capitalize',
        className,
      )}
    >
      {localizeResourceStatusLabel(status, t)}
    </Badge>
  )
}
