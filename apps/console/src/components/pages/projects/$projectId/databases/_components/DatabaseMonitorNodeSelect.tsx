import { useMemo } from 'react'
import { Copy, Database, type LucideIcon } from 'lucide-react'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type DatabaseMonitorNodeOption = {
  ordinal: number
}

/** Primary (0) plus read replicas 1..N for dedicated monitor gauges. */
export function buildDatabaseMonitorNodeOptions(
  replicaCount: number,
): DatabaseMonitorNodeOption[] {
  const count = Math.max(0, Math.floor(replicaCount))
  const options: DatabaseMonitorNodeOption[] = [{ ordinal: 0 }]
  for (let i = 1; i <= count; i++) {
    options.push({ ordinal: i })
  }
  return options
}

function getDatabaseMonitorNodeLabel(
  ordinal: number,
  t: (text: string) => string,
): string {
  if (ordinal <= 0) return t('Primary')
  return `${t('Read replica')} ${ordinal}`
}

function getDatabaseMonitorNodeIcon(ordinal: number): LucideIcon {
  return ordinal <= 0 ? Database : Copy
}

function DatabaseMonitorNodeOptionContent({
  ordinal,
  className,
}: {
  ordinal: number
  className?: string
}) {
  const t = useT()
  const Icon = getDatabaseMonitorNodeIcon(ordinal)
  return (
    <span className={cn('flex items-center gap-2', className)}>
      <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      <span className="truncate">{getDatabaseMonitorNodeLabel(ordinal, t)}</span>
    </span>
  )
}

type DatabaseMonitorNodeSelectProps = {
  replicaCount: number
  value: number
  onValueChange: (ordinal: number) => void
  className?: string
}

/**
 * Switch dedicated database monitor gauges between primary (ordinal 0)
 * and read replicas (ordinal 1+). Hidden when there are no replicas.
 */
export function DatabaseMonitorNodeSelect({
  replicaCount,
  value,
  onValueChange,
  className,
}: DatabaseMonitorNodeSelectProps) {
  const t = useT()
  const options = useMemo(
    () => buildDatabaseMonitorNodeOptions(replicaCount),
    [replicaCount],
  )

  if (replicaCount <= 0) {
    return null
  }

  const selectedOrdinal =
    options.find((option) => option.ordinal === value)?.ordinal ?? 0

  return (
    <Select
      value={String(selectedOrdinal)}
      onValueChange={(next) => {
        const parsed = Number.parseInt(next, 10)
        if (Number.isFinite(parsed)) {
          onValueChange(parsed)
        }
      }}
    >
      <SelectTrigger
        size="sm"
        className={cn('h-7 w-[180px] text-[12px]', className)}
        aria-label={t('Instance')}
      >
        <SelectValue>
          <DatabaseMonitorNodeOptionContent ordinal={selectedOrdinal} />
        </SelectValue>
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem
            key={option.ordinal}
            value={String(option.ordinal)}
            className="text-[13px]"
          >
            <DatabaseMonitorNodeOptionContent ordinal={option.ordinal} />
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
