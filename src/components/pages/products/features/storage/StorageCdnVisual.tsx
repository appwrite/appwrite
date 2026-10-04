import { Globe, ImageIcon, Zap } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtIconBadge,
  ArtLiveDot,
  ArtPanel,
  floatStyle,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

/** Edge PoPs placed on the outer ring by angle (degrees, clockwise from the end side). */
const EDGES = [
  { code: 'lhr', ms: 8, angle: 270 },
  { code: 'iad', ms: 12, angle: 200 },
  { code: 'nrt', ms: 16, angle: 340 },
  { code: 'gru', ms: 24, angle: 155 },
  { code: 'sin', ms: 19, angle: 25 },
  { code: 'syd', ms: 21, angle: 90 },
] as const

function ringPosition(angle: number) {
  const radians = (angle * Math.PI) / 180
  return { left: `${50 + 50 * Math.cos(radians)}%`, top: `${50 + 50 * Math.sin(radians)}%` }
}

export function StorageCdnVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[440px] w-full max-w-[540px]">
      <div
        className="absolute left-1/2 top-1/2 size-[260px] -translate-x-1/2 -translate-y-1/2 sm:size-[330px]"
        aria-hidden
      >
        <div className="absolute inset-0 rounded-full border border-dashed border-foreground/15" />
        <div className="product-hero-orbit absolute inset-[16%] rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.45)]" />
        <div className="product-hero-orbit-reverse absolute inset-[30%] rounded-full border border-dashed border-[rgb(var(--tone2-rgb)/0.5)]" />
        <div className="absolute inset-[20%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.22),transparent_70%)]" />
      </div>

      <div className="absolute left-1/2 top-1/2 size-[260px] -translate-x-1/2 -translate-y-1/2 sm:size-[330px]">
        {EDGES.map((edge, index) => (
          <span
            key={edge.code}
            className="product-hero-rise absolute z-[2] -translate-x-1/2 -translate-y-1/2"
            style={riseStyle(500 + index * 110, ringPosition(edge.angle))}
          >
            <span
              dir="ltr"
              className="product-hero-float inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2 py-1 font-mono text-[10px] text-foreground shadow-sm dark:bg-card"
              style={floatStyle(index * 420)}
            >
              <ArtLiveDot className="size-1.5" />
              {edge.code}
              <span className="text-muted-foreground">{edge.ms} ms</span>
            </span>
          </span>
        ))}
      </div>

      <ArtPanel
        className="absolute left-1/2 top-1/2 z-[1] w-[min(196px,52%)] -translate-x-1/2 -translate-y-1/2"
        innerClassName="product-tone-shadow px-3.5 py-3 text-center"
        delayMs={60}
      >
        <span className="mx-auto flex size-9 items-center justify-center rounded-xl bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
          <Zap className="size-4" aria-hidden />
        </span>
        <p className="mt-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Region cache')}
        </p>
        <p dir="ltr" className="mt-0.5 font-mono text-[12px] font-medium text-foreground">
          fra
        </p>
        <p className="mt-0.5 text-[11px] leading-4 text-muted-foreground">{t('Repeat transforms stay fast')}</p>
        <Badge variant="success" className="mt-2 text-[10px]">
          {t('Cache hit')}
        </Badge>
      </ArtPanel>

      <ArtChip className="start-0 top-0" delayMs={300}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={ImageIcon} tone="secondary" />
          <div className="min-w-0">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Transform')}
            </p>
            <p dir="ltr" className="font-mono text-[11px] text-foreground">
              preview?width=640
            </p>
            <p className="text-[10px] text-muted-foreground">{t('Processed once')}</p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="end-0 top-[2%] hidden sm:block" delayMs={700} floatDelayMs={900}>
        <div className="flex items-center gap-1.5">
          <Globe className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
          <span className="text-[11px] font-medium text-foreground">{t('120+ edge locations')}</span>
        </div>
      </ArtChip>

      <ArtChip className="bottom-0 end-0" delayMs={1100} floatDelayMs={1400}>
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{t('CDN edge')}</p>
        <p className="mt-0.5 text-[12px] font-medium text-foreground">{t('Nearest PoP')}</p>
        <p className="text-[10px] text-muted-foreground">{t('Low latency worldwide')}</p>
      </ArtChip>
    </div>
  )
}
