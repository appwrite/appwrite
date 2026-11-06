'use client'

import { useCallback, useState } from 'react'
import { Filter, Radio, X } from 'lucide-react'
import type { RealtimeConfiguredSubscription } from '@/lib/realtime/debugger-prefs'
import type { SubscriptionQueryEntry } from '@/lib/realtime/subscription-queries'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { getQueryDisplayPartsFromEntry, QueryFilterForm } from './QueryFilterForm'
import { QueryExpression } from './QueryExpression'
import { useT } from '@/lib/i18n/translate'

function LiveIndicator() {
  const t = useT()
  return (
    <span className="inline-flex shrink-0 items-center gap-1 rounded-md bg-emerald-500/[0.08] px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:text-emerald-400">
      <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden />
      {t('Live')}
    </span>
  )
}

function ConfigurationSubscriptionRow({
  entry,
  live,
  onRemove,
  onAddQuery,
  onRemoveQuery,
}: {
  entry: RealtimeConfiguredSubscription
  live: boolean
  onRemove: () => void
  onAddQuery: (query: SubscriptionQueryEntry) => void
  onRemoveQuery: (queryId: string) => void
}) {
  const t = useT()
  const [queryPopoverOpen, setQueryPopoverOpen] = useState(false)

  const handleAddQuery = useCallback(
    (query: SubscriptionQueryEntry) => {
      onAddQuery(query)
      setQueryPopoverOpen(false)
    },
    [onAddQuery],
  )

  return (
    <li className="rounded-lg border border-border/60 bg-background/40 transition-colors hover:border-border hover:bg-muted/20">
      <div className="group flex min-h-9 items-center gap-2 px-2.5 py-2">
        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/30 text-muted-foreground">
          <Radio className="h-3.5 w-3.5" aria-hidden />
        </div>

        <code
          className="min-w-0 flex-1 truncate font-mono text-[12px] font-medium leading-none text-foreground"
          title={entry.channel}
        >
          {entry.channel}
        </code>

        {live ? <LiveIndicator /> : null}

        <div className="flex shrink-0 items-center gap-0.5">
          <Popover open={queryPopoverOpen} onOpenChange={setQueryPopoverOpen}>
            <PopoverTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-[11px] text-muted-foreground hover:text-foreground"
              >
                <Filter className="me-1 h-3 w-3" />
                {t('Query')}
              </Button>
            </PopoverTrigger>
            <PopoverContent
              align="end"
              sideOffset={8}
              className="w-[min(96vw,22rem)] overflow-hidden rounded-xl border-border p-0 shadow-lg"
            >
              <div className="border-b border-border px-4 py-3">
                <p className="text-[13px] font-semibold text-foreground">
                  {t('Add query')}
                </p>
                <p className="mt-1 text-[12px] text-muted-foreground">
                  {t('Filter events for this subscription only.')}
                </p>
              </div>
              <div className="px-4 py-3">
                <QueryFilterForm
                  submitLabel={t('Add query')}
                  onSubmit={handleAddQuery}
                  onCancel={() => setQueryPopoverOpen(false)}
                />
              </div>
            </PopoverContent>
          </Popover>

          <button
            type="button"
            className="flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground opacity-60 transition-[color,opacity,background-color] hover:bg-muted hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring group-hover:opacity-100"
            aria-label={`${t('Remove subscription')} ${entry.channel}`}
            onClick={onRemove}
          >
            <X className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>

      {entry.queries.length > 0 ? (
        <ul className="space-y-1 border-t border-border/60 py-2 ps-9 pe-2.5">
          {entry.queries.map((query) => {
            const parts = getQueryDisplayPartsFromEntry(query)

            return (
              <li
                key={query.id}
                className="group/query flex min-h-7 items-center gap-1.5 rounded-md bg-muted/20 px-2 py-1"
              >
                <Filter className="h-3 w-3 shrink-0 text-muted-foreground" />
                <div className="min-w-0 flex-1 overflow-hidden">
                  <QueryExpression parts={parts} size="compact" />
                </div>
                <button
                  type="button"
                  className="flex h-6 w-6 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground opacity-0 transition-[color,opacity,background-color] hover:bg-muted hover:text-foreground focus-visible:opacity-100 group-hover/query:opacity-100"
                  aria-label={t('Remove query')}
                  onClick={() => onRemoveQuery(query.id)}
                >
                  <X className="h-3 w-3" />
                </button>
              </li>
            )
          })}
        </ul>
      ) : null}
    </li>
  )
}

export function ConfigurationItemsList({
  configuredSubscriptions,
  isConnected,
  isSubscriptionLive,
  onRemoveSubscription,
  onAddSubscriptionQuery,
  onRemoveSubscriptionQuery,
}: {
  configuredSubscriptions: RealtimeConfiguredSubscription[]
  isConnected: boolean
  isSubscriptionLive: (subscriptionId: string) => boolean
  onRemoveSubscription: (entryId: string) => void
  onAddSubscriptionQuery: (
    subscriptionId: string,
    query: SubscriptionQueryEntry,
  ) => void
  onRemoveSubscriptionQuery: (subscriptionId: string, queryId: string) => void
}) {
  const t = useT()
  if (configuredSubscriptions.length === 0) {
    return (
      <div className="flex min-h-[160px] items-center justify-center px-4 py-8 text-center">
        <p className="max-w-[14rem] text-[13px] leading-relaxed text-muted-foreground">
          {t('Add a subscription to configure channels and query filters.')}
        </p>
      </div>
    )
  }

  return (
    <div className="px-2 py-2">
      <ul className="space-y-1.5">
        {configuredSubscriptions.map((entry) => (
          <ConfigurationSubscriptionRow
            key={entry.id}
            entry={entry}
            live={isConnected && isSubscriptionLive(entry.id)}
            onRemove={() => void onRemoveSubscription(entry.id)}
            onAddQuery={(query) => onAddSubscriptionQuery(entry.id, query)}
            onRemoveQuery={(queryId) =>
              onRemoveSubscriptionQuery(entry.id, queryId)
            }
          />
        ))}
      </ul>
    </div>
  )
}
