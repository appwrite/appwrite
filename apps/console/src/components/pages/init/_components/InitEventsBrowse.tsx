import { Link } from '@tanstack/react-router'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { listBrowsableInitEvents } from '@/lib/init/init-event-browse'
import type { LaunchEventStatus } from '@/lib/init/types'
import { cn } from '@/lib/utils'

function InitBrowseStatusBadge({ status }: { status: LaunchEventStatus }) {
  const t = useT()

  if (status === 'active') {
    return (
      <Badge variant="success" className="text-[10px] shrink-0">
        {t('Live now')}
      </Badge>
    )
  }
  if (status === 'upcoming') {
    return (
      <Badge variant="warning" className="text-[10px] shrink-0">
        {t('Upcoming')}
      </Badge>
    )
  }
  return null
}

export function InitEventsBrowse({ activeEventId }: { activeEventId: string }) {
  const t = useT()
  const events = listBrowsableInitEvents()

  if (events.length <= 1) return null

  return (
    <section
      className="border-t border-border pt-8"
      aria-labelledby="init-events-browse-heading"
    >
      <div className="space-y-1">
        <h2
          id="init-events-browse-heading"
          className="text-[15px] font-semibold text-foreground"
        >
          {t('Browse Init events')}
        </h2>
        <p className="text-[13px] text-muted-foreground">
          {t('Switch between past and upcoming Init launch weeks.')}
        </p>
      </div>

      <ul className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
        {events.map((item) => {
          const isActive = item.id === activeEventId
          return (
            <li key={item.id}>
              <Link
                to="/init"
                search={{ event: item.slug }}
                className={cn(
                  'link-unstyled flex h-full flex-col rounded-xl border bg-card/50 p-4 transition-colors',
                  'hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                  isActive
                    ? 'border-foreground/20 bg-muted/50 shadow-sm'
                    : 'border-border',
                )}
                aria-current={isActive ? 'page' : undefined}
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-[14px] font-semibold text-foreground">
                    {item.editionLabel}
                  </span>
                  <InitBrowseStatusBadge status={item.status} />
                </div>
                <p className="mt-1 text-[12px] font-medium uppercase tracking-wider text-muted-foreground">
                  {t(item.dateRangeLabel)}
                </p>
              </Link>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
