'use client'

import { cn } from '@/lib/utils'

export type QueryExpressionParts = {
  attribute: string
  operator: string
  value: string | null
  valueType?: string
}

function abbreviateValueType(label: string): string {
  switch (label) {
    case 'String':
      return 'str'
    case 'Integer':
      return 'int'
    case 'Float':
      return 'flt'
    case 'Boolean':
      return 'bool'
    case 'Datetime':
      return 'dt'
    default:
      return label.slice(0, 4).toLowerCase()
  }
}

export function QueryExpression({
  parts,
  className,
  size = 'default',
}: {
  parts: QueryExpressionParts
  className?: string
  size?: 'default' | 'compact'
}) {
  const isCompact = size === 'compact'

  if (isCompact) {
    return (
      <div
        className={cn(
          'flex min-w-0 items-center gap-1 overflow-hidden font-mono text-[11px] leading-none',
          className,
        )}
        title={
          parts.value
            ? `${parts.valueType ? `${parts.valueType} ` : ''}${parts.attribute} ${parts.operator} ${parts.value}`
            : `${parts.valueType ? `${parts.valueType} ` : ''}${parts.attribute} ${parts.operator}`
        }
      >
        {parts.valueType ? (
          <span className="shrink-0 rounded bg-muted/50 px-1 py-0.5 text-[10px] uppercase tracking-wide text-muted-foreground">
            {abbreviateValueType(parts.valueType)}
          </span>
        ) : null}
        <span className="min-w-0 shrink truncate font-medium text-foreground">
          {parts.attribute}
        </span>
        <span className="shrink-0 text-muted-foreground">{parts.operator}</span>
        {parts.value ? (
          <span className="min-w-0 truncate text-foreground">{parts.value}</span>
        ) : null}
      </div>
    )
  }

  return (
    <div
      className={cn(
        'flex min-w-0 items-center gap-1.5 overflow-hidden font-mono text-[12px]',
        className,
      )}
    >
      {parts.valueType ? (
        <span className="shrink-0 rounded-md border border-border/60 bg-muted/40 px-1.5 py-0.5 text-[10px] text-muted-foreground">
          {abbreviateValueType(parts.valueType)}
        </span>
      ) : null}
      <span className="min-w-0 shrink truncate rounded-md border border-border/60 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
        {parts.attribute}
      </span>
      <span className="shrink-0 text-muted-foreground">{parts.operator}</span>
      {parts.value ? (
        <span
          className="min-w-0 truncate rounded-md border border-border/60 bg-muted/40 px-2 py-0.5 text-foreground"
          title={parts.value}
        >
          {parts.value}
        </span>
      ) : null}
    </div>
  )
}
