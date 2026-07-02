'use client'

import { useCallback, useState, type FormEvent } from 'react'
import { Plus, Route } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import type { RealtimeConfiguredSubscription } from '@/lib/realtime/debugger-prefs'
import type { SubscriptionQueryEntry } from '@/lib/realtime/subscription-queries'
import { ConfigurationItemsList } from './ConfigurationItemsList'
import { useT } from '@/lib/i18n/translate'

const SUGGESTED_CHANNELS = [
  'account',
  'files',
  'teams',
  'databases.*.tables.*.rows.*',
] as const

type ConfigurationPanelProps = {
  isConnected: boolean
  configuredSubscriptions: RealtimeConfiguredSubscription[]
  isSubscriptionLive: (subscriptionId: string) => boolean
  onAddSubscription: (channel: string) => void | Promise<void>
  onRemoveSubscription: (entryId: string) => void | Promise<void>
  onAddSubscriptionQuery: (
    subscriptionId: string,
    query: SubscriptionQueryEntry,
  ) => void | Promise<void>
  onRemoveSubscriptionQuery: (
    subscriptionId: string,
    queryId: string,
  ) => void | Promise<void>
  onOpenChannelBuilder: () => void
}

export function ConfigurationPanel({
  isConnected,
  configuredSubscriptions,
  isSubscriptionLive,
  onAddSubscription,
  onRemoveSubscription,
  onAddSubscriptionQuery,
  onRemoveSubscriptionQuery,
  onOpenChannelBuilder,
}: ConfigurationPanelProps) {
  const t = useT()
  const [subscriptionPopoverOpen, setSubscriptionPopoverOpen] = useState(false)
  const [channelDraft, setChannelDraft] = useState('')

  const handleAddSubscription = useCallback(
    async (event: FormEvent) => {
      event.preventDefault()
      const channel = channelDraft.trim()
      if (!channel) return

      await onAddSubscription(channel)
      setChannelDraft('')
      setSubscriptionPopoverOpen(false)
    },
    [channelDraft, onAddSubscription],
  )

  const handleSuggestedSubscribe = useCallback(
    async (channel: string) => {
      await onAddSubscription(channel)
      setChannelDraft('')
      setSubscriptionPopoverOpen(false)
    },
    [onAddSubscription],
  )

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      <div className="shrink-0 border-b border-border px-4 py-2.5">
        <Popover
          open={subscriptionPopoverOpen}
          onOpenChange={setSubscriptionPopoverOpen}
        >
          <PopoverTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 w-full text-[13px]"
            >
              <Plus className="me-1.5 h-3.5 w-3.5" />
                {t('Subscriptions')}
            </Button>
          </PopoverTrigger>
          <PopoverContent
            align="start"
            sideOffset={8}
            className="w-[min(96vw,22rem)] overflow-hidden rounded-xl border-border p-0 shadow-lg"
          >
            <div className="border-b border-border px-4 py-3">
              <p className="text-[13px] font-semibold text-foreground">
                {t('Add subscription')}
              </p>
              <p className="mt-1 text-[12px] text-muted-foreground">
                {isConnected
                  ? t(
                      'Subscribe to a channel. Add query filters to each subscription separately.',
                    )
                  : t(
                      'Configure a channel now. Add queries per subscription, then connect.',
                    )}
              </p>
            </div>
            <form
              onSubmit={(event) => void handleAddSubscription(event)}
              className="space-y-3 px-4 py-3"
            >
              <div className="flex gap-2">
                <Input
                  value={channelDraft}
                  onChange={(event) => setChannelDraft(event.target.value)}
                  placeholder="e.g. account"
                  className="h-9 min-w-0 flex-1 font-mono text-[13px]"
                  spellCheck={false}
                />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 shrink-0 px-3"
                  onClick={onOpenChannelBuilder}
                >
                  <Route className="h-4 w-4" />
                </Button>
              </div>

              <div className="space-y-1.5">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Suggested')}
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {SUGGESTED_CHANNELS.map((channel) => (
                    <Button
                      key={channel}
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-7 font-mono text-[11px]"
                      onClick={() => void handleSuggestedSubscribe(channel)}
                    >
                      {channel}
                    </Button>
                  ))}
                </div>
              </div>

              <Button
                type="submit"
                size="sm"
                className="h-9 w-full text-[13px]"
                disabled={!channelDraft.trim()}
              >
                {t('Add subscription')}
              </Button>
            </form>
          </PopoverContent>
        </Popover>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
        <ConfigurationItemsList
          configuredSubscriptions={configuredSubscriptions}
          isConnected={isConnected}
          isSubscriptionLive={isSubscriptionLive}
          onRemoveSubscription={onRemoveSubscription}
          onAddSubscriptionQuery={onAddSubscriptionQuery}
          onRemoveSubscriptionQuery={onRemoveSubscriptionQuery}
        />
      </div>
    </div>
  )
}
