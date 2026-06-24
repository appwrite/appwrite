'use client'

import { cn } from '@/lib/utils'

export type QueryExpressionParts = {
  attribute: string
  operator: string
  value: string | null
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

  return (
    <div
      className={cn(
        'flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 font-mono',
        isCompact ? 'text-[11px]' : 'text-[12px]',
        className,
      )}
    >
      <span
        className={cn(
          'shrink-0 rounded-md border border-border/60 bg-muted/40 font-medium text-foreground',
          isCompact ? 'px-1.5 py-0.5' : 'px-2 py-0.5',
        )}
      >
        {parts.attribute}
      </span>
      <span className="shrink-0 text-muted-foreground">{parts.operator}</span>
      {parts.value ? (
        <span
          className={cn(
            'min-w-0 truncate rounded-md border border-border/60 bg-muted/40 text-foreground',
            isCompact ? 'max-w-[8rem] px-1.5 py-0.5' : 'max-w-[12rem] px-2 py-0.5',
          )}
          title={parts.value}
        >
          {parts.value}
        </span>
      ) : null}
    </div>
  )
}
