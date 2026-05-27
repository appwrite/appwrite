import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import {
  INIT_REACTIONS,
  readStoredInitReaction,
  writeStoredInitReaction,
} from '@/lib/init/reactions'
import type { InitDisplayEvent } from '@/lib/init/types'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { InitTicketPeek } from './InitTicketPeek'

interface InitReactionsBarProps {
  event: InitDisplayEvent
}

export function InitReactionsBar({ event }: InitReactionsBarProps) {
  const { data: account } = useQuery(consoleAccountQueryOptions())
  const [activeReactionId, setActiveReactionId] = useState<string | null>(null)

  useEffect(() => {
    setActiveReactionId(readStoredInitReaction(event.id))
  }, [event.id])

  const handleReaction = (reactionId: string) => {
    const next = activeReactionId === reactionId ? null : reactionId
    setActiveReactionId(next)
    writeStoredInitReaction(event.id, next)
  }

  return (
    <footer
      className={cn(
        'sticky bottom-0 z-40 shrink-0 overflow-visible',
        'border-t border-border bg-background/95 backdrop-blur-sm supports-[backdrop-filter]:bg-background/80',
      )}
      aria-label="Add a reaction"
    >
      <div className="group/init-reactions relative mx-auto w-full max-w-7xl px-4 sm:px-6">
        <InitTicketPeek
          dateRangeLabel={event.dateRangeLabel}
          account={account}
          ticketHref="https://appwrite.io/init/ticket"
        />

        <div className="relative flex min-h-14 items-center justify-center gap-0.5 py-2 sm:gap-1 sm:py-2.5">
          <p className="sr-only">React to Init</p>
          {INIT_REACTIONS.map((reaction) => {
            const isActive = activeReactionId === reaction.id
            return (
              <Tooltip key={reaction.id}>
                <TooltipTrigger asChild>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={cn(
                      'size-9 shrink-0 rounded-md text-[18px] leading-none sm:size-10 sm:text-[20px]',
                      isActive &&
                        'bg-[color-mix(in_srgb,var(--brand-cta)_12%,transparent)] ring-1 ring-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))]',
                    )}
                    aria-label={reaction.label}
                    aria-pressed={isActive}
                    onClick={() => handleReaction(reaction.id)}
                  >
                    <span aria-hidden>{reaction.emoji}</span>
                  </Button>
                </TooltipTrigger>
                <TooltipContent side="top" className="text-[12px]">
                  {reaction.label}
                </TooltipContent>
              </Tooltip>
            )
          })}
        </div>
      </div>
    </footer>
  )
}
