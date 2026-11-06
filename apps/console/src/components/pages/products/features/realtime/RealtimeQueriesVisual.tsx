import { Filter } from 'lucide-react'
import {
  ArtConnector,
  ArtPanel,
  ArtToken,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const INCOMING_EVENTS = [
  { id: 'row_7c21', person: 'paige', match: true },
  { id: 'row_91bd', person: 'walter', match: false },
  { id: 'row_4e08', person: 'paige', match: true },
] as const

export function RealtimeQueriesVisual() {
  const t = useT()

  return (
    <div className="mx-auto flex h-[420px] w-full max-w-[540px] flex-col justify-center">
      <ArtPanel innerClassName="p-3.5" delayMs={60}>
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Events on the channel')}
        </p>
        <ul className="mt-2.5 space-y-1.5">
          {INCOMING_EVENTS.map((event, index) => (
            <li
              key={event.id}
              className={cn(
                'product-hero-rise flex items-center gap-2.5 rounded-lg border px-2.5 py-1.5',
                event.match
                  ? 'border-border bg-muted/20'
                  : 'border-dashed border-border/70 opacity-60',
              )}
              style={riseStyle(200 + index * 140)}
            >
              <span
                className={cn(
                  'size-1.5 shrink-0 rounded-full',
                  event.match ? 'bg-[var(--tone-ink)]' : 'bg-muted-foreground/50',
                )}
                aria-hidden
              />
              <span dir="ltr" className="min-w-0 flex-1 truncate font-mono text-[10.5px]">
                <ArtToken tone="property">person</ArtToken>:{' '}
                <ArtToken tone="string">&apos;{event.person}&apos;</ArtToken>
              </span>
              <span className="shrink-0 text-[10px] text-muted-foreground">
                {t(event.match ? 'match' : 'dropped')}
              </span>
            </li>
          ))}
        </ul>
      </ArtPanel>

      <div className="mx-auto h-6 w-0 shrink-0">
        <ArtConnector orientation="vertical" className="border-[rgb(var(--tone-rgb)/0.6)]" />
      </div>

      <ArtPanel
        innerClassName="product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] p-3.5 dark:border-[rgb(var(--tone-rgb)/0.45)]"
        delayMs={420}
      >
        <div className="flex items-center gap-2">
          <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
            <Filter className="size-3.5" aria-hidden />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-[12px] font-semibold text-foreground">
              {t('Filtered on the server')}
            </p>
            <p dir="ltr" className="mt-0.5 truncate font-mono text-[10.5px]">
              [<ArtToken tone="class">Query</ArtToken>.<ArtToken tone="function">equal</ArtToken>(
              <ArtToken tone="string">&apos;person&apos;</ArtToken>, [
              <ArtToken tone="string">&apos;paige&apos;</ArtToken>])]
            </p>
          </div>
        </div>
      </ArtPanel>
    </div>
  )
}
