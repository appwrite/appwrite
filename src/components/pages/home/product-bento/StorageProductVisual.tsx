import { useState, type CSSProperties, type ReactNode } from 'react'
import { ArrowRight } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from './MockSyntax'

const RING_SIZE = 32
const RING_STROKE = 2
const RING_R = (RING_SIZE - RING_STROKE) / 2
const RING_C = 2 * Math.PI * RING_R
const SAVED_PCT = 62

function PreviewReductionRing({ children }: { children: ReactNode }) {
  return (
    <div
      className="relative shrink-0"
      style={{ width: RING_SIZE, height: RING_SIZE }}
      aria-hidden
    >
      <svg
        width={RING_SIZE}
        height={RING_SIZE}
        viewBox={`0 0 ${RING_SIZE} ${RING_SIZE}`}
        className="-rotate-90"
      >
        <circle
          cx={RING_SIZE / 2}
          cy={RING_SIZE / 2}
          r={RING_R}
          fill="none"
          className="stroke-border"
          strokeWidth={RING_STROKE}
        />
        <circle
          cx={RING_SIZE / 2}
          cy={RING_SIZE / 2}
          r={RING_R}
          fill="none"
          className={cn('product-bento-storage-ring-arc motion-reduce:transition-none', productBentoIdle.ring)}
          strokeWidth={RING_STROKE}
          strokeLinecap="round"
          strokeDasharray={RING_C}
          strokeDashoffset={RING_C}
          style={
            {
              '--ring-offset-idle': RING_C,
              '--ring-offset-hover': RING_C * (1 - SAVED_PCT / 100),
            } as CSSProperties
          }
        />
      </svg>
      <div className="pointer-events-none absolute inset-0 grid place-items-center">{children}</div>
    </div>
  )
}

function MockSliderRow({
  label,
  idleFill,
  hoverFill,
  idleValue,
  hoverValue,
  sliderKey,
  animationKey,
  delayMs = 0,
}: {
  label: string
  idleFill: number
  hoverFill: number
  idleValue?: string
  hoverValue?: string
  sliderKey?: string
  animationKey?: number
  delayMs?: number
}) {
  const t = useT()
  const trackKey =
    sliderKey && animationKey !== undefined
      ? `${sliderKey}-${animationKey}`
      : sliderKey

  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between gap-2">
        <span className="text-[10px] text-muted-foreground">{t(label)}</span>
        {idleValue && hoverValue ? (
          <span className={cn('relative min-w-[2.25rem] text-end text-[10px] tabular-nums', productBentoIdle.text)}>
            <span className="transition-opacity duration-200 group-hover:opacity-0 motion-reduce:group-hover:opacity-100">
              {idleValue}
            </span>
            <span
              className="absolute inset-0 opacity-0 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100"
              style={{ transitionDelay: `${delayMs}ms` }}
            >
              {hoverValue}
            </span>
          </span>
        ) : null}
      </div>
      <div className="h-1 overflow-hidden rounded-full bg-muted">
        <div
          key={trackKey}
          className={cn('product-bento-storage-slider h-full rounded-full motion-reduce:!w-[var(--slider-hover)]', productBentoIdle.slider)}
          style={
            {
              '--slider-idle': `${idleFill}%`,
              '--slider-hover': `${hoverFill}%`,
              '--slider-delay': `${delayMs}ms`,
              width: `${idleFill}%`,
            } as CSSProperties
          }
        />
      </div>
    </div>
  )
}

function MockImagePreview() {
  return (
    <div
      className={cn(
        'product-bento-storage-preview relative aspect-[4/3] w-[68%] max-w-[7.5rem] overflow-hidden rounded-md border border-border bg-muted/40',
      )}
    >
      <div className="absolute inset-0 bg-gradient-to-br from-muted-foreground/10 via-muted-foreground/5 to-transparent" />
      <div
        className="pointer-events-none absolute inset-2 rounded-sm border border-dashed border-primary/0 opacity-0 transition-[opacity,border-color] duration-500 group-hover:border-primary/45 group-hover:opacity-100 motion-reduce:group-hover:opacity-100"
        style={{ transitionDelay: '160ms' }}
      />
    </div>
  )
}

export function StorageProductVisual() {
  const t = useT()
  const [widthSliderKey, setWidthSliderKey] = useState(0)

  return (
    <div
      className="absolute inset-0 flex flex-col overflow-hidden"
      onMouseEnter={() => setWidthSliderKey((key) => key + 1)}
    >
      <div className={cn('flex h-full min-h-0 flex-col', productBentoContainer.shell)}>
        <div className={cn(productBentoContainer.header, 'flex shrink-0 items-center justify-between gap-2 px-3 py-2')}>
          <span className={cn('rounded-sm bg-background px-2 py-0.5 text-[10px] font-medium shadow-sm', productBentoIdle.text)}>
            {t('Design')}
          </span>
          <Badge
            variant="success"
            className="h-5 px-1.5 text-[9px] opacity-0 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100"
            style={{ transitionDelay: '360ms' }}
          >
            AVIF
          </Badge>
        </div>

        <div className="flex min-h-0 flex-1 flex-col overflow-hidden sm:flex-row">
          <div className="relative min-h-[5rem] min-w-0 flex-1 overflow-hidden bg-background sm:min-h-[7rem]">
            <div
              className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:22px_22px]"
              aria-hidden
            />
            <div className="relative z-[1] flex h-full items-center justify-center p-2 sm:p-3">
              <MockImagePreview />
            </div>
          </div>

          <aside className="w-full shrink-0 border-t border-border bg-card/70 p-2 sm:w-[42%] sm:border-s sm:border-t-0 sm:p-2.5">
            <p className={cn('text-[11px] font-medium', productBentoIdle.text)}>
              {t('Transform')}
            </p>
            <div className="mt-1.5 space-y-1.5 sm:mt-2.5 sm:space-y-2">
              <MockSliderRow
                label="Width (px)"
                idleValue="1200"
                hoverValue="480"
                sliderKey="width-px"
                animationKey={widthSliderKey}
                idleFill={100}
                hoverFill={40}
                delayMs={120}
              />
              <MockSliderRow
                label="Quality"
                idleFill={82}
                hoverFill={64}
                delayMs={240}
              />
            </div>
          </aside>
        </div>

        <div className="flex shrink-0 items-center gap-2 border-t border-border bg-muted/8 px-2.5 py-2 sm:px-3">
          <PreviewReductionRing>
            <span className={cn('inline-flex items-baseline tabular-nums text-[8px] font-medium', productBentoIdle.text)}>
              <span className="product-bento-storage-ring-value opacity-40 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100">
                {SAVED_PCT}
              </span>
              <span className="text-[7px] font-normal text-muted-foreground">%</span>
            </span>
          </PreviewReductionRing>
          <p className={cn('min-w-0 flex-1 text-[10px] leading-tight', productBentoIdle.text)}>
            <span className="font-medium tabular-nums">2.4 MB</span>
            <ArrowRight className="mx-0.5 inline size-2.5 shrink-0 align-text-bottom text-muted-foreground sm:mx-1" />
            <span className="font-medium tabular-nums opacity-70 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100">
              920 KB
            </span>
          </p>
        </div>
      </div>
    </div>
  )
}
