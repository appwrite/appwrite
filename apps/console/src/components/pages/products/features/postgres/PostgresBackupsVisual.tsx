import { History } from 'lucide-react'
import {
  ArtIconBadge,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Newest snapshot first, the way the Backups page lists them. */
const SNAPSHOTS = [
  { id: 'bk-0931', taken: 'Oct 2, 03:00 UTC', size: '1.8 GB' },
  { id: 'bk-0930', taken: 'Oct 1, 03:00 UTC', size: '1.8 GB' },
  { id: 'bk-0929', taken: 'Sep 30, 03:00 UTC', size: '1.7 GB' },
] as const

export function PostgresBackupsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-6 text-start">
      <p className="ps-9 text-[10px] uppercase tracking-wider text-muted-foreground">
        {t('Backups')}
      </p>

      <div className="relative mt-2.5">
        <span
          className="absolute start-[13px] inset-y-4 border-s border-dashed border-foreground/25"
          aria-hidden
        />

        {SNAPSHOTS.map((snapshot, index) => (
          <div
            key={snapshot.id}
            className={cn('relative ps-9', index > 0 && 'mt-2.5')}
          >
            <span
              className="product-hero-rise absolute start-[8px] top-[18px] size-2.5 rotate-45 rounded-[2px] border border-[var(--tone-ink)] bg-background dark:bg-card"
              style={riseStyle(260 + index * 180)}
              aria-hidden
            />

            <ArtPanel
              className="w-full sm:w-[300px]"
              innerClassName={cn(
                'flex items-center gap-3 px-3 py-2.5',
                index === 0 &&
                  'product-tone-shadow border-[rgb(var(--tone-rgb)/0.4)] dark:border-[rgb(var(--tone-rgb)/0.4)]',
              )}
              delayMs={240 + index * 180}
            >
              <span
                dir="ltr"
                className="min-w-0 truncate font-mono text-[11px] text-foreground"
              >
                {snapshot.taken}
              </span>
              <span
                dir="ltr"
                className="ms-auto shrink-0 font-mono text-[10px] text-muted-foreground"
              >
                {snapshot.size}
              </span>
            </ArtPanel>
          </div>
        ))}
      </div>

      <ArtPanel
        className="mt-4 w-fit max-w-full sm:absolute sm:end-0 sm:top-8 sm:mt-0"
        innerClassName="flex items-center gap-2 px-3 py-2.5"
        delayMs={820}
        float
        floatDelayMs={700}
      >
        <ArtIconBadge icon={History} tone="secondary" />
        <div className="min-w-0">
          <p className="text-[11px] font-medium text-foreground">
            {t('Point-in-time recovery')}
          </p>
          <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">
            14:32:05 UTC
          </p>
        </div>
      </ArtPanel>
    </div>
  )
}
