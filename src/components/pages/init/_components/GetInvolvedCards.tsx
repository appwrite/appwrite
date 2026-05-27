import type { LaunchEvent } from '@/lib/init/types'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitExploringActivity } from '@/lib/init/init-presence-activity'
import { ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'

interface GetInvolvedCardsProps {
  event: LaunchEvent
}

export function GetInvolvedCards({ event }: GetInvolvedCardsProps) {
  const { setTransientActivity } = useInitPresenceActivity()

  if (event.getInvolved.length === 0) return null

  return (
    <section className="space-y-4">
      <h3 className="text-[15px] font-semibold text-foreground">
        Ways to get involved
      </h3>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {event.getInvolved.map((item) => {
          const Icon = item.icon
          const inner = (
            <>
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon className="size-5" aria-hidden />
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
            'group flex w-full items-center gap-3 rounded-xl border border-border bg-card/50 p-4 text-left transition-colors',
            item.href && 'hover:border-border hover:bg-accent/50',
          )
          const presenceHandlers = {
            onMouseEnter: () => setTransientActivity(buildInitExploringActivity(item.title)),
            onMouseLeave: () => setTransientActivity(null),
            onFocus: () => setTransientActivity(buildInitExploringActivity(item.title)),
            onBlur: () => setTransientActivity(null),
          }

          if (item.href) {
            return (
              <a
                key={item.id}
                href={item.href}
                target="_blank"
                rel="noopener noreferrer"
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
