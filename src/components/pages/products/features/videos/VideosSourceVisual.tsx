import { ArrowDown, FileVideo, Video } from 'lucide-react'
import { MockStatPill } from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const PROBED_METADATA = [
  { label: 'Resolution', value: '1920x1080' },
  { label: 'Duration', value: '02:14' },
  { label: 'Frame rate', value: '30 fps' },
  { label: 'Aspect ratio', value: '16:9' },
  { label: 'Video codec', value: 'H.264' },
  { label: 'Audio codec', value: 'AAC' },
] as const

export function VideosSourceVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame eyebrow={t('Storage')} title={t('Create video')}>
      <div className="space-y-3">
        <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-background/80 px-3 py-2.5">
          <div className="flex min-w-0 items-center gap-2.5">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
              <FileVideo className="size-3.5 text-muted-foreground" aria-hidden />
            </span>
            <div className="min-w-0">
              <p className="truncate text-[12px] font-medium text-foreground">launch.mp4</p>
              <p className="truncate font-mono text-[10px] text-muted-foreground">
                media / 84 MB
              </p>
            </div>
          </div>
          <span className="shrink-0 text-[11px] text-muted-foreground">{t('Source file')}</span>
        </div>

        <div className="flex justify-center">
          <ArrowDown className="size-3.5 text-muted-foreground" aria-hidden />
        </div>

        <div className="rounded-lg border border-foreground/10 bg-muted/30 p-3">
          <div className="flex items-center gap-2">
            <Video className="size-3.5 text-muted-foreground" aria-hidden />
            <p className="text-[12px] font-semibold text-foreground">{t('Probed metadata')}</p>
          </div>
          <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
            {PROBED_METADATA.map((item) => (
              <MockStatPill key={item.label} label={item.label} value={item.value} />
            ))}
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
