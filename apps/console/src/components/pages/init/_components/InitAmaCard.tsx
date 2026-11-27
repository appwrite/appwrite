import type { LaunchEvent, LaunchEventScheduleItem } from '@/lib/init/types'
import { useT } from '@/lib/i18n/translate'
import { InitScheduleRow, INIT_SCHEDULE_PLATFORM_META } from './InitScheduleRow'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

/** Saturday after launch week: the date as the day cards print it (event times are Pacific). */
function formatAmaDate(startsAt: string) {
  return new Date(startsAt)
    .toLocaleDateString('en-US', {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
      timeZone: 'America/Los_Angeles',
    })
    .toUpperCase()
}

/**
 * The week's Reddit AMA, after the last launch day, as its own card below the
 * day cards. Built from schedule items, so it follows the event's data.
 */
export function InitAmaCard({
  event,
  items,
  isRecapMode = false,
}: {
  event: LaunchEvent
  items: LaunchEventScheduleItem[]
  isRecapMode?: boolean
}) {
  const t = useT()
  const first = items[0]
  if (!first) return null
  const reddit = INIT_SCHEDULE_PLATFORM_META.reddit

  return (
    <article
      id="init-ama"
      aria-label="Reddit AMA"
      className="scroll-mt-28 overflow-hidden rounded-xl border border-border bg-card/50"
    >
      <header className="border-b border-border px-6 py-3">
        <p className="text-center text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          {formatAmaDate(first.startsAt)}
        </p>
      </header>

      <div className="flex flex-col gap-5 p-6 sm:flex-row sm:items-center sm:gap-6">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-full border border-border bg-muted/40">
          <img
            src={reddit.icon}
            alt=""
            aria-hidden
            className={cn('size-5', PUBLIC_ICON_MUTED_CLASSES)}
          />
        </span>
        <div className="min-w-0">
          <h3 className="font-aeonik-pro text-[clamp(28px,4vw,36px)] font-normal leading-none tracking-tight text-foreground">
            {t('Ask us anything')}
            <span aria-hidden="true" className="heading-cursor" />
          </h3>
          <p className="mt-3 text-[13px] leading-relaxed text-muted-foreground">
            {isRecapMode
              ? t(
                  'The team answered your questions about every Init 5 launch on r/appwrite.',
                )
              : t(
                  'Bring your questions about every Init 5 launch. The team answers live on r/appwrite.',
                )}
          </p>
        </div>
      </div>

      <ul className="divide-y divide-border border-t border-border">
        {items.map((item) => (
          <InitScheduleRow
            key={item.id}
            event={event}
            item={item}
            isRecapMode={isRecapMode}
            inlineWhenWide
          />
        ))}
      </ul>
    </article>
  )
}
