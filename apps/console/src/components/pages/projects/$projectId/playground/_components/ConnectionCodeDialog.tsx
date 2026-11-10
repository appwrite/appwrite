'use client'

import { useMemo, useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'
import {
  buildRealtimeConnectionSnippets,
  type RealtimeSnippetSdkId,
} from '@/lib/realtime/connection-snippets'
import type { RealtimeConfiguredSubscription } from '@/lib/realtime/debugger-prefs'
import { useT } from '@/lib/i18n/translate'

type ConnectionCodeDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  subscriptions: RealtimeConfiguredSubscription[]
}

export function ConnectionCodeDialog({
  open,
  onOpenChange,
  projectId,
  subscriptions,
}: ConnectionCodeDialogProps) {
  const t = useT()
  const snippets = useMemo(
    () =>
      buildRealtimeConnectionSnippets({
        endpoint: getProjectApiEndpoint(projectId),
        projectId,
        subscriptions: subscriptions.map((entry) => ({
          channel: entry.channel,
          queries: entry.queries,
        })),
      }),
    [projectId, subscriptions],
  )

  const [activeSdkId, setActiveSdkId] = useState<RealtimeSnippetSdkId>('client-web')

  const activeSnippet =
    snippets.find((snippet) => snippet.id === activeSdkId) ?? snippets[0]

  const subscriptionCount = subscriptions.length
  const queryCount = subscriptions.reduce(
    (total, entry) => total + entry.queries.length,
    0,
  )

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[min(90dvh,720px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Realtime connection code')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {subscriptionCount > 0 ? (
              <>
                {t('One subscribe call per configured subscription')}
                {subscriptionCount === 1 ? (
                  <>
                    {' '}
                    {t('for')}{' '}
                    <code className="font-mono text-[12px]">
                      {subscriptions[0]?.channel}
                    </code>
                  </>
                ) : (
                  <> ({subscriptionCount} {t('total')})</>
                )}
                {queryCount > 0 ? (
                  <>
                    . {t('Query filters are included per subscription where configured.')}
                  </>
                ) : (
                  <>.</>
                )}{' '}
                {t(
                  'Authenticated channels require an active session or JWT on the client.',
                )}
              </>
            ) : (
              <>
                {t(
                  'Add a subscription in the debugger to generate channel-specific subscribe calls. Add query filters on each subscription as needed.',
                )}
              </>
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="border-t border-border" />

        <div className="flex min-h-0 flex-1 flex-col px-6 py-4">
          {activeSnippet ? (
            <ConnectCodeExample
              code={activeSnippet.code}
              language={activeSnippet.language}
              tabs={snippets.map((snippet) => ({
                id: snippet.id,
                label: snippet.label,
              }))}
              activeTabId={activeSdkId}
              onTabChange={(id) => setActiveSdkId(id as RealtimeSnippetSdkId)}
              selectorVariant="dropdown"
              fixedHeight="min(420px, 50dvh)"
              className="min-h-0 flex-1"
            />
          ) : null}
        </div>

        <div className="border-t border-border bg-muted/30 px-6 py-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('Close')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
