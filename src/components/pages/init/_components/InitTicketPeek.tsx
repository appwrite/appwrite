import type { Models } from '@appwrite.io/console'
import { InitWordmark } from '@/components/pages/init/_components/InitWordmark'
import { INIT_TICKET_SECTION_HASH, isExternalInitHref } from '@/lib/init/links'
import { cn } from '@/lib/utils'

interface InitTicketPeekProps {
  dateRangeLabel: string
  account?: Models.User | null
  ticketHref?: string
  className?: string
}

export function InitTicketPeek({
  dateRangeLabel,
  account,
  ticketHref = INIT_TICKET_SECTION_HASH,
  className,
}: InitTicketPeekProps) {
  const holderName = account?.name?.trim() || account?.email?.split('@')[0] || 'Guest'

  const ticket = (
    <div
      className={cn(
        'relative w-[440px] transition-[transform] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]',
        'translate-y-[72%]',
        'group-hover/init-reactions:translate-y-[-48px]',
        'group-focus-within/init-reactions:translate-y-[-48px]',
      )}
    >
      <div
        className="absolute inset-x-10 bottom-0 h-8 rounded-full bg-black/15 blur-xl dark:bg-black/50"
        aria-hidden
      />
      <div
        className={cn(
          'relative flex h-[560px] w-[380px] flex-col justify-between overflow-hidden rounded-xl border',
          'border-[color-mix(in_srgb,var(--brand-cta)_40%,var(--border))]',
          'bg-gradient-to-br from-card via-card to-[color-mix(in_srgb,var(--brand-cta)_12%,var(--card))]',
          'p-10 shadow-xl shadow-black/10 dark:shadow-black/45',
          'before:absolute before:inset-y-16 before:-end-7 before:w-14 before:rounded-full before:border-2 before:border-border before:bg-background',
        )}
      >
        <div className="space-y-4">
          <InitWordmark className="text-[60px] text-foreground" />
          <p className="text-[40px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
            {dateRangeLabel}
          </p>
        </div>
        <div className="border-t border-dashed border-border pt-8">
          <p className="truncate text-[50px] font-medium leading-tight text-foreground">
            {holderName}
          </p>
          <p className="mt-2 text-[35px] font-semibold uppercase tracking-wider text-muted-foreground">
            Init pass
          </p>
        </div>
      </div>
    </div>
  )

  return (
    <div
      className={cn(
        'pointer-events-none absolute bottom-0 start-0 z-10 flex items-end overflow-visible',
        className,
      )}
    >
      {ticketHref ? (
        <a
          href={ticketHref}
          {...(isExternalInitHref(ticketHref)
            ? { target: '_blank', rel: 'noopener noreferrer' }
            : {})}
          className="pointer-events-auto outline-none"
          aria-label={account ? 'View your Init ticket' : 'Claim your Init ticket'}
        >
          {ticket}
        </a>
      ) : (
        <div className="pointer-events-auto">{ticket}</div>
      )}
    </div>
  )
}
