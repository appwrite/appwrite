import { useQuery } from '@tanstack/react-query'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import {
  INIT_REACTIONS,
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
import { INIT_TICKET_SECTION_HASH } from '@/lib/init/links'

interface InitReactionsBarProps {
  event: InitDisplayEvent
}

export function InitReactionsBar({ event }: InitReactionsBarProps) {
  const { data: account } = useQuery(consoleAccountQueryOptions())

  const handleReaction = (reactionId: string) => {
    writeStoredInitReaction(event.id, reactionId)
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
          ticketHref={INIT_TICKET_SECTION_HASH}
        />

        <div className="relative flex min-h-14 items-center justify-center gap-0.5 py-2 sm:gap-1 sm:py-2.5">
          <p className="sr-only">React to Init</p>
          {INIT_REACTIONS.map((reaction) => {
            const Icon = reaction.icon
            return (
              <Tooltip key={reaction.id}>
                <TooltipTrigger asChild>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-9 shrink-0 rounded-md text-muted-foreground sm:size-10"
                    aria-label={reaction.label}
                    onClick={() => handleReaction(reaction.id)}
                  >
                    <Icon className="size-[18px] sm:size-5" aria-hidden />
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
