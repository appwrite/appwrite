import { useId, type CSSProperties } from 'react'
import { Link } from '@tanstack/react-router'
import { ArrowUpRight } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import {
  INIT5_FIVE_GLYPH_PATH,
  INIT5_FIVE_GLYPH_VIEWBOX,
} from '@/lib/init/init5-hero/five-glyph'
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

const NUMERAL_CLASS =
  'pointer-events-none absolute w-auto opacity-60 transition-opacity duration-500 group-hover:opacity-100 group-aria-[current=page]:opacity-100'

function useSvgId(prefix: string) {
  return `${prefix}-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`
}

/** Init 5: the hero's electric 5, a neon outline with light running round it. */
function ElectricFive() {
  const gradientId = useSvgId('init-browse-five')
  const stroke = `url(#${gradientId})`
  return (
    <svg
      aria-hidden
      viewBox={INIT5_FIVE_GLYPH_VIEWBOX}
      className={cn(
        NUMERAL_CLASS,
        'end-5 -bottom-7 h-[150px] overflow-visible',
      )}
    >
      <defs>
        <linearGradient id={gradientId} x1="0.1" y1="0" x2="0.9" y2="1">
          <stop offset="0%" style={stop('var(--network-globe-region)')} />
          <stop offset="45%" style={stop('var(--brand-cta)')} />
          <stop offset="100%" style={stop('var(--network-globe-edge)')} />
        </linearGradient>
      </defs>
      <path d={INIT5_FIVE_GLYPH_PATH} fill={stroke} opacity={0.08} />
      <path
        d={INIT5_FIVE_GLYPH_PATH}
        fill="none"
        stroke={stroke}
        strokeWidth={2.4}
        strokeLinejoin="round"
        opacity={0.22}
        style={{ filter: 'blur(1.5px)' }}
      />
      <path
        d={INIT5_FIVE_GLYPH_PATH}
        fill="none"
        stroke={stroke}
        strokeWidth={0.7}
        strokeLinejoin="round"
      />
      {[0, 50].map((offset) => (
        <path
          key={offset}
          d={INIT5_FIVE_GLYPH_PATH}
          pathLength={100}
          className="init-browse-five-trail"
          style={{ animationDelay: `${-offset * 0.06}s` }}
        />
      ))}
    </svg>
  )
}

/** Init 4 and earlier: the number made of the Init 4 hero's glowing pink particles. */
function ParticleNumeral({ value }: { value: number }) {
  const patternId = useSvgId('init-browse-dots')
  return (
    <svg
      aria-hidden
      viewBox="0 0 120 140"
      className={cn(NUMERAL_CLASS, '-end-3 -bottom-8 h-[150px]')}
    >
      <defs>
        <pattern
          id={patternId}
          width="12"
          height="12"
          patternUnits="userSpaceOnUse"
        >
          <circle cx="3" cy="3" r="1.9" style={{ fill: 'var(--brand-cta)' }} />
          <circle cx="9" cy="3" r="1.2" style={{ fill: 'var(--brand-cta)' }} />
          <circle cx="3" cy="9" r="1.4" style={{ fill: 'var(--brand-cta)' }} />
          <circle cx="9" cy="9" r="2.1" style={{ fill: 'var(--brand-cta)' }} />
        </pattern>
      </defs>
      <text
        x="60"
        y="122"
        textAnchor="middle"
        className="font-aeonik-pro"
        fontSize="150"
        fontWeight="700"
        fill={`url(#${patternId})`}
        style={{
          filter:
            'drop-shadow(0 0 4px color-mix(in oklch, var(--brand-cta) 70%, transparent)) drop-shadow(0 0 14px color-mix(in oklch, var(--brand-cta) 35%, transparent))',
        }}
      >
        {value}
      </text>
    </svg>
  )
}

/** Each edition's number in its own event's look. */
function EditionNumeral({
  eventId,
  value,
}: {
  eventId: string
  value: number
}) {
  return eventId === 'init-nov-2026' ? (
    <ElectricFive />
  ) : (
    <ParticleNumeral value={value} />
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
                  <EditionNumeral eventId={item.id} value={item.edition} />
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
