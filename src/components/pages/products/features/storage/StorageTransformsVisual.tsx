import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { MockStatPill } from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { useT } from '@/lib/i18n/translate'

const TRANSFORM_PARAMS = [
  { label: 'width', value: '640' },
  { label: 'height', value: '480' },
  { label: 'gravity', value: 'center' },
  { label: 'quality', value: '85' },
  { label: 'output', value: 'webp' },
  { label: 'rotation', value: '0' },
] as const

export function StorageTransformsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame eyebrow="Preview endpoint" title="getFilePreview()">
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">sunset.png</p>
            <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">
              /v1/storage/buckets/photos/files/sunset/preview
            </p>
          </div>
          <Badge variant="success" className="shrink-0 text-[10px]">
            {t('CDN cached')}
          </Badge>
        </div>

        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,9rem)]">
          <div className="overflow-hidden rounded-lg border border-border bg-muted/20">
            <div className="flex aspect-[4/3] items-center justify-center bg-gradient-to-br from-orange-400/30 via-rose-400/20 to-violet-500/30">
              <div className="rounded-md border border-border/60 bg-background/70 px-3 py-1.5 text-[11px] text-muted-foreground">
                640 × 480 · webp
              </div>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-2">
            {TRANSFORM_PARAMS.map((param) => (
              <MockStatPill key={param.label} label={param.label} value={param.value} />
            ))}
          </div>
        </div>

        <div className="rounded-lg border border-dashed border-border bg-muted/15 px-3 py-2.5">
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Resize, crop, format, quality, borders, and rotation. No duplicate uploads.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
