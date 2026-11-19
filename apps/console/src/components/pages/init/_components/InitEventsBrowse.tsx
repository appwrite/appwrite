import { useId, type CSSProperties } from 'react'
import { Link } from '@tanstack/react-router'
import { ArrowUpRight } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { listBrowsableInitEvents } from '@/lib/init/init-event-browse'
import type { LaunchEventStatus } from '@/lib/init/types'
import { cn } from '@/lib/utils'

const stop = (color: string): CSSProperties => ({ stopColor: color })

function InitBrowseStatus({ status }: { status: LaunchEventStatus }) {
  const t = useT()
  const live = status === 'active'
  const upcoming = status === 'upcoming'

  return (
    <span className="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.24em] text-muted-foreground">
      <span className="relative flex size-1.5" aria-hidden>
        {live ? (
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-[var(--brand-cta)] opacity-60 motion-reduce:hidden" />
        ) : null}
        <span
          className={cn(
            'relative inline-flex size-1.5 rounded-full',
            live || upcoming
              ? 'bg-[var(--brand-cta)]'
              : 'bg-muted-foreground/50',
          )}
        />
      </span>
      {live ? t('Live now') : upcoming ? t('Upcoming') : t('Past')}
    </span>
  )
}

/** The edition number drawn in light outline, like the Init 5 hero's 5. */
function EditionNumeral({ value }: { value: number }) {
  const gradientId = `init-browse-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`
  return (
    <svg
      aria-hidden
      viewBox="0 0 120 140"
      className="pointer-events-none absolute -end-3 -bottom-8 h-[150px] w-auto opacity-50 transition-opacity duration-500 group-hover:opacity-90 group-aria-[current=page]:opacity-90"
    >
      <defs>
        <linearGradient id={gradientId} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" style={stop('var(--network-globe-region)')} />
          <stop offset="50%" style={stop('var(--brand-cta)')} />
          <stop offset="100%" style={stop('var(--network-globe-edge)')} />
        </linearGradient>
      </defs>
      <text
        x="60"
        y="122"
        textAnchor="middle"
        className="font-aeonik-pro"
        fontSize="150"
        fontWeight="600"
        fill={`url(#${gradientId})`}
        fillOpacity={0.06}
        stroke={`url(#${gradientId})`}
        strokeWidth={1.2}
        style={{
          filter:
            'drop-shadow(0 0 10px color-mix(in oklch, var(--brand-cta) 35%, transparent))',
        }}
      >
        {value}
      </text>
    </svg>
  )
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

      <ul className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
        {events.map((item) => {
          const isActive = item.id === activeEventId
          return (
            <li key={item.id}>
              <Link
                to="/init"
                search={{ event: item.slug }}
                aria-current={isActive ? 'page' : undefined}
                className={cn(
                  'link-unstyled group relative flex h-full min-h-[136px] flex-col justify-between rounded-2xl p-5 transition-colors duration-300',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                  // The page you're on gets the light trail; the rest a
                  // hairline that warms on hover.
                  isActive
                    ? 'init5-light-frame bg-card'
                    : 'border border-border bg-card/60 hover:border-[color-mix(in_oklch,var(--brand-cta)_35%,var(--border))]',
                )}
              >
                {isActive ? (
                  <span aria-hidden className="init5-light-halo" />
                ) : null}
                {/* Clip the numeral without clipping the frame's halo. */}
                <span
                  aria-hidden
                  className="pointer-events-none absolute inset-0 overflow-hidden rounded-[inherit]"
                >
                  <span className="absolute inset-0 bg-[radial-gradient(ellipse_70%_90%_at_100%_100%,color-mix(in_oklch,var(--brand-cta)_12%,transparent),transparent_70%)] opacity-0 transition-opacity duration-500 group-hover:opacity-100 group-aria-[current=page]:opacity-100" />
                  <EditionNumeral value={item.edition} />
                </span>

                <div className="relative flex items-center justify-between gap-3">
                  <InitBrowseStatus status={item.status} />
                  <ArrowUpRight
                    aria-hidden
                    className={cn(
                      'size-4 text-muted-foreground transition-all duration-300 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-foreground rtl:-scale-x-100',
                      isActive && 'opacity-0',
                    )}
                  />
                </div>

                <div className="relative mt-6">
                  <p className="font-aeonik-pro text-[22px] font-semibold leading-none tracking-tight text-foreground">
                    {item.editionLabel}
                  </p>
                  <p className="mt-3 text-[11px] font-semibold uppercase tracking-[0.26em] text-muted-foreground">
                    {t(item.dateRangeLabel)}
                  </p>
                </div>
              </Link>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
