import type { CSSProperties } from 'react'
import {
  ArtChip,
  ArtLiveDot,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const SCORE = 94

const DIMENSIONS = [
  { id: 'startup', label: 'Startup', value: 100 },
  { id: 'smoothness', label: 'Smoothness', value: 100 },
  { id: 'picture', label: 'Picture quality', value: 91 },
  { id: 'stability', label: 'Stability', value: 98 },
] as const

const KPIS = [
  { id: 'startup', label: 'First frame', value: '86 ms' },
  { id: 'rebuffer', label: 'Rebuffering', value: '0.0%' },
  { id: 'dropped', label: 'Dropped frames', value: '0.00%' },
] as const

export function VideosInspectorVisual() {
  const t = useT()
  const size = 72
  const stroke = 7
  const radius = (size - stroke) / 2
  const circumference = 2 * Math.PI * radius

  return (
    <div className="relative mx-auto w-full max-w-[520px] pb-14">
      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(80)}
        title={t('Inspector')}
        trailing={
          <span className="flex items-center gap-1.5">
            <ArtLiveDot className="size-1.5" />
            <span className="text-[10px] font-medium text-[var(--tone-ink)]">{t('Live')}</span>
          </span>
        }
        bodyClassName="space-y-3 px-3 py-3"
      >
        <div className="flex items-center gap-4">
          <div className="relative shrink-0" style={{ width: size, height: size }}>
            <svg width={size} height={size} className="-rotate-90" aria-hidden>
              <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke="var(--muted)"
                strokeWidth={stroke}
              />
              <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke="var(--tone-ink)"
                strokeWidth={stroke}
                strokeLinecap="round"
                strokeDasharray={circumference}
                strokeDashoffset={circumference * (1 - SCORE / 100)}
              />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
              <span dir="ltr" className="font-aeonik-pro text-[20px] leading-none tracking-tight text-foreground">
                {SCORE}
              </span>
            </div>
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-[11px] font-semibold text-foreground">{t('Experience score')}</p>
            <p className="text-[10px] text-muted-foreground">{t('Excellent')}</p>
            <div className="mt-2 space-y-1.5">
              {DIMENSIONS.map((dimension) => (
                <div key={dimension.id} className="flex items-center gap-2">
                  <span className="w-[88px] shrink-0 truncate text-[10px] text-muted-foreground">
                    {t(dimension.label)}
                  </span>
                  <div className="h-1 flex-1 overflow-hidden rounded-full bg-muted">
                    <div
                      className="product-hero-fill h-full origin-left rounded-full bg-[var(--tone-ink)]"
                      style={
                        {
                          '--fill-delay': '500ms',
                          '--fill-duration': '1.4s',
                          width: `${dimension.value}%`,
                        } as CSSProperties
                      }
                    />
                  </div>
                  <span dir="ltr" className="w-5 text-end font-mono text-[10px] tabular-nums text-foreground">
                    {dimension.value}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="grid grid-cols-3 gap-2">
          {KPIS.map((kpi, index) => (
            <div
              key={kpi.id}
              className="product-hero-rise min-w-0 rounded-lg border border-border px-2 py-2"
              style={riseStyle(280 + index * 80)}
            >
              <p className="truncate text-[10px] text-muted-foreground">{t(kpi.label)}</p>
              <p dir="ltr" className="mt-0.5 text-[13px] font-semibold tabular-nums text-foreground">
                {kpi.value}
              </p>
            </div>
          ))}
        </div>
      </ArtWindow>

      <ArtChip className="bottom-0 end-[6%]" delayMs={900} floatDelayMs={700}>
        <p className="text-[11px] font-medium text-foreground">{t('Follows the playing video')}</p>
        <p className="text-[10px] text-muted-foreground">{t('Pop out beside the player')}</p>
      </ArtChip>
    </div>
  )
}
