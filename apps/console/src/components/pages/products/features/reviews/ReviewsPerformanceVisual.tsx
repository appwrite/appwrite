import type { CSSProperties } from 'react'
import { ArtPanel } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TILES = [
  { label: 'Pull requests', value: '128', hint: '412 reviews' },
  { label: 'Approved', value: '72%', hint: '48% first try' },
  { label: 'Findings / PR', value: '1.8', hint: '6 critical' },
  { label: 'Useful', value: '81%', hint: 'Fixed, not dismissed' },
] as const

/** Width plus a staggered start for a `product-hero-fill` segment. */
function fillStyle(widthPercent: number, delayMs: number): CSSProperties {
  return {
    width: `${widthPercent}%`,
    '--fill-delay': `${delayMs}ms`,
  } as CSSProperties
}

/** Settled findings per category: fixed, still open, dismissed. */
const CATEGORIES = [
  { name: 'bug', fixed: 34, open: 6, dismissed: 4 },
  { name: 'security', fixed: 12, open: 2, dismissed: 1 },
  { name: 'error-handling', fixed: 9, open: 3, dismissed: 2 },
  { name: 'maintainability', fixed: 4, open: 2, dismissed: 9, noisy: true },
] as const

/** The Performance tab: what the reviewer caught, how often it was right, and which categories are noise. */
export function ReviewsPerformanceVisual() {
  const t = useT()
  const max = Math.max(
    ...CATEGORIES.map(
      (category) => category.fixed + category.open + category.dismissed,
    ),
  )

  return (
    <div className="relative mx-auto w-full max-w-[580px] py-4 text-start">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {TILES.map((tile, index) => (
          <ArtPanel
            key={tile.label}
            delayMs={60 + index * 80}
            innerClassName="px-3 py-2.5"
          >
            <p className="text-[10px] text-muted-foreground">{t(tile.label)}</p>
            <p
              dir="ltr"
              className="mt-0.5 font-aeonik-pro text-[22px] leading-none tracking-tight text-foreground"
            >
              {tile.value}
            </p>
            <p className="mt-1 text-[10px] text-muted-foreground">
              {t(tile.hint)}
            </p>
          </ArtPanel>
        ))}
      </div>

      <ArtPanel
        className="mt-3"
        delayMs={420}
        innerClassName="product-tone-shadow p-3.5"
      >
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-[12px] font-semibold text-foreground">
            {t('Findings by category')}
          </p>
          <span className="flex items-center gap-3 text-[10px] text-muted-foreground">
            <span className="flex items-center gap-1">
              <span
                className="size-2 rounded-[2px] bg-[var(--tone-ink)]"
                aria-hidden
              />
              {t('Fixed')}
            </span>
            <span className="flex items-center gap-1">
              <span
                className="size-2 rounded-[2px] bg-[rgb(var(--tone2-rgb))]"
                aria-hidden
              />
              {t('Still open')}
            </span>
            <span className="flex items-center gap-1">
              <span
                className="size-2 rounded-[2px] bg-muted-foreground/40"
                aria-hidden
              />
              {t('Dismissed')}
            </span>
          </span>
        </div>
        <ul className="mt-3 space-y-2.5">
          {CATEGORIES.map((category, index) => {
            const total = category.fixed + category.open + category.dismissed
            const noisy = 'noisy' in category && category.noisy
            return (
              <li
                key={category.name}
                className="grid grid-cols-[9.5rem_minmax(0,1fr)] items-center gap-3"
              >
                <span className="flex items-center gap-1.5 truncate text-[11px] text-foreground">
                  <span dir="ltr" className="truncate">
                    {category.name}
                  </span>
                  {noisy ? (
                    <span className="shrink-0 rounded-full bg-amber-400/20 px-1.5 text-[9.5px] font-medium text-amber-700 dark:text-amber-300">
                      {t('Noisy')}
                    </span>
                  ) : null}
                </span>
                <span
                  className="flex h-2.5 overflow-hidden rounded-full bg-muted"
                  style={{ width: `${(total / max) * 100}%` }}
                >
                  {[
                    {
                      value: category.fixed,
                      className: 'bg-[var(--tone-ink)]',
                    },
                    {
                      value: category.open,
                      className: 'bg-[rgb(var(--tone2-rgb))]',
                    },
                    {
                      value: category.dismissed,
                      className: 'bg-muted-foreground/40',
                    },
                  ].map((segment, segmentIndex) => (
                    <span
                      key={segmentIndex}
                      className={cn(
                        'product-hero-fill h-full',
                        segment.className,
                      )}
                      style={fillStyle(
                        (segment.value / total) * 100,
                        600 + index * 120,
                      )}
                    />
                  ))}
                </span>
              </li>
            )
          })}
        </ul>
        <p className="mt-3 border-t border-border/70 pt-2.5 text-[10.5px] leading-4 text-muted-foreground">
          {t('A learning can tell the reviewer to flag fewer of them.')}
        </p>
      </ArtPanel>
    </div>
  )
}
