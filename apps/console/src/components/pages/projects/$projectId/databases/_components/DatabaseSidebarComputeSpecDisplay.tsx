import { Fragment } from 'react'
import { Cable, Cpu, MemoryStick } from 'lucide-react'
import type { DatabaseSpecDisplayParts } from '@/lib/database-specs'
import { cn } from '@/lib/utils'

type DatabaseSidebarComputeSpecDisplayProps = {
  parts: DatabaseSpecDisplayParts
  label?: string | null
  className?: string
}

function SpecMetric({
  icon: Icon,
  value,
}: {
  icon: typeof Cpu
  value: string
}) {
  return (
    <span className="inline-flex min-w-0 max-w-full items-center gap-0.5">
      <Icon className="h-3 w-3 shrink-0 opacity-70" aria-hidden />
      <span className="truncate tabular-nums">{value}</span>
    </span>
  )
}

export function DatabaseSidebarComputeSpecDisplay({
  parts,
  label,
  className,
}: DatabaseSidebarComputeSpecDisplayProps) {
  if (parts.variant === 'serverless' || parts.variant === 'label') {
    return (
      <span className={cn('min-w-0 truncate', className)}>
        {label ?? parts.label}
      </span>
    )
  }

  const metrics = [
    parts.cpu ? (
      <SpecMetric key="cpu" icon={Cpu} value={parts.cpu} />
    ) : null,
    parts.memory ? (
      <SpecMetric key="memory" icon={MemoryStick} value={parts.memory} />
    ) : null,
    parts.connections ? (
      <SpecMetric key="connections" icon={Cable} value={parts.connections} />
    ) : null,
  ].filter(Boolean)

  if (metrics.length === 0) {
    return (
      <span className={cn('min-w-0 truncate', className)}>
        {label ?? parts.label}
      </span>
    )
  }

  return (
    <span
      className={cn(
        'flex min-w-0 items-center gap-1 overflow-hidden',
        className,
      )}
    >
      {metrics.map((metric, index) => (
        <Fragment key={index}>
          {index > 0 ? (
            <span className="shrink-0 text-muted-foreground/40" aria-hidden>
              ·
            </span>
          ) : null}
          {metric}
        </Fragment>
      ))}
    </span>
  )
}
