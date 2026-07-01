import type { Models } from '@appwrite.io/console'
import type { InitDisplayEvent } from '@/lib/init/types'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitExploringActivity } from '@/lib/init/init-presence-activity'
import { ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'
import { isExternalInitHref } from '@/lib/init/links'

import { INIT_GET_INVOLVED_SECTION_ID, INIT_TICKET_SECTION_ID } from '@/lib/init/init-section-ids'

interface GetInvolvedCardsProps {
  event: InitDisplayEvent
  account?: Models.User | null
}

function resolveInvolvementItem(
  item: InitDisplayEvent['getInvolved'][number],
  isAuthenticated: boolean,
) {
  if (item.id !== 'ticket' || !isAuthenticated) return item

  return {
    ...item,
    title: 'Share ticket',
    href: `#${INIT_TICKET_SECTION_ID}`,
  }
}

export function GetInvolvedCards({ event, account }: GetInvolvedCardsProps) {
  const { setTransientActivity } = useInitPresenceActivity()
  const isAuthenticated = Boolean(account)
  const items = event.getInvolved.map((item) =>
    resolveInvolvementItem(item, isAuthenticated),
  )

  if (items.length === 0) return null

  const sectionTitle = event.isRecapMode
    ? (event.recap?.getInvolvedSectionTitle ?? 'Keep exploring')
    : 'Ways to get involved'

  const itemCount = items.length

  return (
    <section id={INIT_GET_INVOLVED_SECTION_ID} className="space-y-4">
      <h3 className="text-[15px] font-semibold text-foreground">{sectionTitle}</h3>

      <div
        className={cn(
          'grid gap-3',
          itemCount === 1 && 'max-w-xl grid-cols-1',
          itemCount === 2 && 'grid-cols-1 sm:grid-cols-2',
          itemCount >= 3 && 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
        )}
      >
        {items.map((item) => {
          const Icon = item.icon
          const inner = (
            <>
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                {item.iconSrc ? (
                  <img
                    src={item.iconSrc}
                    alt=""
                    className="size-5 dark:invert-0"
                    aria-hidden
                  />
                ) : Icon ? (
                  <Icon className="size-5" aria-hidden />
                ) : null}
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-foreground">{item.title}</p>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                  {item.description}
                </p>
              </div>
              <ChevronRight
                className="size-4 shrink-0 text-[var(--brand-cta)]"
                aria-hidden
              />
            </>
          )

          const className = cn(
            'group flex w-full items-center gap-3 rounded-xl border border-border bg-card/50 p-4 text-start transition-colors',
            item.href && 'hover:border-border hover:bg-accent/50',
          )
          const presenceHandlers = {
            onMouseEnter: () => setTransientActivity(buildInitExploringActivity(item.title)),
            onMouseLeave: () => setTransientActivity(null),
            onFocus: () => setTransientActivity(buildInitExploringActivity(item.title)),
            onBlur: () => setTransientActivity(null),
          }

          const isExternalLink = item.href ? isExternalInitHref(item.href) : false

          if (item.href) {
            return (
              <a
                key={item.id}
                href={item.href}
                {...(isExternalLink
                  ? { target: '_blank', rel: 'noopener noreferrer' }
                  : {})}
                className={className}
                {...presenceHandlers}
              >
                {inner}
              </a>
            )
          }

          return (
            <div key={item.id} className={className} {...presenceHandlers}>
              {inner}
            </div>
          )
        })}
      </div>
    </section>
  )
}
