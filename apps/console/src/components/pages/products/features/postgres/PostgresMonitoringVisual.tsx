import { Activity, CircleGauge, Cpu } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TILES = [
  { id: 'cpu', label: 'CPU', value: '38%', icon: Cpu },
  { id: 'qps', label: 'Queries / sec', value: '1.4k', icon: Activity },
  { id: 'cache', label: 'Cache hit', value: '99.4%', icon: CircleGauge },
] as const

/** Connections over the selected range, as a share of the specification limit. */
const CONNECTION_SERIES = [
  0.22, 0.3, 0.26, 0.41, 0.52, 0.38, 0.46, 0.63, 0.58, 0.71, 0.54, 0.49, 0.66,
  0.78,
] as const

export function PostgresMonitoringVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-6 text-start">
      <div className="grid grid-cols-3 gap-2">
        {TILES.map((tile, index) => {
          const Icon = tile.icon
          return (
            <div
              key={tile.id}
              className="product-hero-rise rounded-xl border border-border bg-background/95 px-2.5 py-2 dark:bg-card"
              style={riseStyle(80 + index * 100)}
            >
              <p className="flex items-center gap-1.5 text-[10px] text-muted-foreground">
                <Icon
                  className="size-3 shrink-0 text-[var(--tone-ink)]"
                  aria-hidden
                />
                <span className="truncate">{t(tile.label)}</span>
              </p>
              <p
                dir="ltr"
                className="mt-1 font-aeonik-pro text-[17px] tracking-tight text-foreground"
              >
                {tile.value}
              </p>
            </div>
          )
        })}
      </div>

      <div className="product-hero-rise mt-6" style={riseStyle(420)}>
        <div className="flex items-end justify-between gap-2">
          <div>
            <p className="text-[10px] uppercase tracking-wider text-muted-foreground">
              {t('Connections')}
            </p>
            <p dir="ltr" className="font-mono text-[11px] text-foreground">
              142 / 1,000
            </p>
          </div>
          <p className="text-[10px] text-muted-foreground">
            {t('Last 6 hours')}
          </p>
        </div>

        <div
          dir="ltr"
          className="mt-3 flex h-[110px] items-end gap-[5px]"
          aria-hidden
        >
          {CONNECTION_SERIES.map((point, index) => (
            <div
              key={`bar-${index}`}
              className={cn(
                'product-hero-rise flex-1 rounded-t-[3px]',
                index === CONNECTION_SERIES.length - 1
                  ? 'bg-[var(--tone-ink)]'
                  : 'bg-[rgb(var(--tone-rgb)/0.45)]',
              )}
              style={riseStyle(500 + index * 60, { height: `${point * 100}%` })}
            />
          ))}
        </div>
        <div className="mt-1 h-px bg-border" aria-hidden />
      </div>
    </div>
  )
}
