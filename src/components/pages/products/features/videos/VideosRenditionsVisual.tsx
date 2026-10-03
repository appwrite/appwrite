import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

type RenditionRow = {
  id: string
  name: string
  size: string
  bitrate: string
  output: string
  status: 'ready' | 'encoding' | 'pending'
  progress: number
}

const RENDITIONS: RenditionRow[] = [
  { id: '1080p', name: '1080p', size: '1920x1080', bitrate: '5000 kbps', output: 'HLS', status: 'ready', progress: 100 },
  { id: '720p', name: '720p', size: '1280x720', bitrate: '2800 kbps', output: 'HLS', status: 'ready', progress: 100 },
  { id: '480p', name: '480p', size: '854x480', bitrate: '1400 kbps', output: 'HLS', status: 'encoding', progress: 64 },
  { id: '360p', name: '360p', size: '640x360', bitrate: '800 kbps', output: 'HLS', status: 'pending', progress: 0 },
]

const STATUS_BADGES = {
  ready: { label: 'Ready', variant: 'success' },
  encoding: { label: 'Encoding', variant: 'processing' },
  pending: { label: 'Pending', variant: 'pending' },
} as const

export function VideosRenditionsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame title={t('Renditions')}>
      <div className="space-y-2">
        {RENDITIONS.map((rendition) => {
          const status = STATUS_BADGES[rendition.status]
          return (
            <div
              key={rendition.id}
              className="rounded-lg border border-border bg-background/80 px-3 py-2.5"
            >
              <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                  <span className="w-11 shrink-0 text-[12px] font-semibold text-foreground">
                    {rendition.name}
                  </span>
                  <span className="truncate font-mono text-[10px] text-muted-foreground">
                    {rendition.size} / {rendition.bitrate}
                  </span>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                  <Badge variant="inactive" className="text-[10px]">
                    {rendition.output}
                  </Badge>
                  <Badge variant={status.variant} className="text-[10px]">
                    {t(status.label)}
                  </Badge>
                </div>
              </div>
              {rendition.status === 'encoding' ? (
                <div className="mt-2 flex items-center gap-2">
                  <div className="h-1 flex-1 overflow-hidden rounded-full bg-muted">
                    <div
                      className="h-full rounded-full bg-blue-500"
                      style={{ width: `${rendition.progress}%` }}
                    />
                  </div>
                  <span className="text-[10px] tabular-nums text-muted-foreground">
                    {rendition.progress}%
                  </span>
                </div>
              ) : null}
            </div>
          )
        })}
      </div>
    </ProductFeatureVisualFrame>
  )
}
