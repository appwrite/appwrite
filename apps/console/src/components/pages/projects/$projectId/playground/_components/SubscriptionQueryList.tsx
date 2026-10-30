'use client'

import {
  getQueryDisplayParts,
  formatQueryStringLabel,
} from '@/lib/realtime/subscription-queries'
import { cn } from '@/lib/utils'
import { QueryExpression } from './QueryExpression'

export function SubscriptionQueryList({
  queries,
  className,
}: {
  queries: string[]
  className?: string
}) {
  if (queries.length === 0) return null

  return (
    <ul className={cn('space-y-1', className)}>
      {queries.map((query, index) => {
        const parts = getQueryDisplayParts(query)

        return (
          <li
            key={`${index}-${query}`}
            className="min-h-7 overflow-hidden rounded-md border border-border/60 bg-muted/20 px-2 py-1"
            title={formatQueryStringLabel(query)}
          >
            {parts ? (
              <QueryExpression parts={parts} size="compact" />
            ) : (
              <span className="block truncate font-mono text-[11px] text-muted-foreground">
                {query}
              </span>
            )}
          </li>
        )
      })}
    </ul>
  )
}
