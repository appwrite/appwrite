import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  ASSISTANT_MESSAGES_PAGE_SIZE,
  fetchAssistantMessagesWithTools,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { DEFAULT_STALE_TIME, isClientQueryEnabled } from '@/lib/react-query/hooks/constants'
import {
  countResourceMutations,
  hasResourceMutations,
} from '@/lib/assistant/resource-mutations'

type ConversationResourceSummaryProps = {
  conversationId: string
  className?: string
  enabled?: boolean
}

export function ConversationResourceSummary({
  conversationId,
  className,
  enabled = true,
}: ConversationResourceSummaryProps) {
  const t = useT()
  const { data } = useQuery({
    // Dedicated key so we can hydrate tools via getMessage without fighting the
    // live chat messages query (which may keep a leaner list payload).
    queryKey: [
      'agent',
      'conversation-resource-stats',
      conversationId,
      ASSISTANT_MESSAGES_PAGE_SIZE,
    ],
    queryFn: () =>
      fetchAssistantMessagesWithTools(
        conversationId,
        ASSISTANT_MESSAGES_PAGE_SIZE,
      ),
    enabled:
      enabled &&
      !!conversationId &&
      isClientQueryEnabled &&
      getActiveProfileFeatures().agent,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })

  const counts = countResourceMutations(data?.messages)
  if (!hasResourceMutations(counts)) return null

  const parts: ReactNode[] = []
  if (counts.created > 0) {
    parts.push(
      <span
        key="created"
        className="text-emerald-600 dark:text-emerald-400"
      >{`+${counts.created}`}</span>,
    )
  }
  if (counts.updated > 0) {
    parts.push(
      <span key="updated" className="text-muted-foreground">
        {`±${counts.updated}`}
      </span>,
    )
  }
  if (counts.deleted > 0) {
    parts.push(
      <span
        key="deleted"
        className="text-rose-600 dark:text-rose-400"
      >{`-${counts.deleted}`}</span>,
    )
  }

  return (
    <span
      className={cn(
        'inline-flex min-w-0 items-center truncate text-[11px] leading-tight text-muted-foreground',
        className,
      )}
      title={t('Resources changed')}
    >
      {parts.map((part, index) => (
        <span key={index}>
          {index > 0 ? (
            <span className="text-muted-foreground/70"> · </span>
          ) : null}
          {part}
        </span>
      ))}
    </span>
  )
}
