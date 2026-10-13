import type { CSSProperties } from 'react'
import { Badge } from '@/components/ui/badge'
import { ArtChip, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type RenditionRow = {
  id: string
  name: string
  size: string
  bitrate: string
  status: 'ready' | 'encoding' | 'pending'
  progress: number
}

const RENDITIONS: RenditionRow[] = [
  { id: '1080p', name: '1080p', size: '1920x1080', bitrate: '5000 kbps', status: 'ready', progress: 100 },
  { id: '720p', name: '720p', size: '1280x720', bitrate: '2800 kbps', status: 'ready', progress: 100 },
  { id: '480p', name: '480p', size: '854x480', bitrate: '1400 kbps', status: 'encoding', progress: 64 },
  { id: '360p', name: '360p', size: '640x360', bitrate: '800 kbps', status: 'pending', progress: 0 },
]

const STATUS_BADGES = {
  ready: { label: 'Ready', variant: 'success' },
  encoding: { label: 'Encoding', variant: 'processing' },
  pending: { label: 'Pending', variant: 'pending' },
} as const

export function VideosRenditionsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[480px] pb-14">
      <p
        className="product-hero-rise mb-3 px-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
        style={riseStyle(40)}
      >
        {t('Renditions')}
      </p>
      <div className="space-y-2">
        {RENDITIONS.map((rendition, index) => {
          const status = STATUS_BADGES[rendition.status]
          return (
            <ArtPanel
              key={rendition.id}
              delayMs={120 + index * 120}
              innerClassName={cn(
                'px-3 py-2.5',
                rendition.status === 'encoding' &&
                  'border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
              )}
            >
              <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                  <span dir="ltr" className="w-11 shrink-0 text-[12px] font-semibold text-foreground">
                    {rendition.name}
                  </span>
                  <span dir="ltr" className="truncate font-mono text-[10px] text-muted-foreground">
                    {rendition.size} · {rendition.bitrate}
                  </span>
                </div>
                <Badge variant={status.variant} className="shrink-0 text-[10px]">
                  {t(status.label)}
                </Badge>
              </div>
              {rendition.status === 'encoding' ? (
                <div className="mt-2 flex items-center gap-2">
                  <div className="h-1 flex-1 overflow-hidden rounded-full bg-muted">
                    <div
                      className="product-hero-fill h-full origin-left rounded-full bg-[var(--tone-ink)]"
                      style={
                        {
                          '--fill-delay': '700ms',
                          '--fill-duration': '2.2s',
                          width: `${rendition.progress}%`,
                        } as CSSProperties
                      }
                    />
                  </div>
                  <span dir="ltr" className="text-[10px] tabular-nums text-muted-foreground">
                    {rendition.progress}%
                  </span>
                </div>
              ) : null}
            </ArtPanel>
          )
        })}
      </div>

      <ArtChip className="bottom-0 end-[6%]" delayMs={900} floatDelayMs={800}>
        <p className="text-[11px] font-medium text-foreground">{t('Default project ladder')}</p>
        <p className="text-[10px] text-muted-foreground">{t('Add a profile for any other bitrate')}</p>
      </ArtChip>
    </div>
  )
}
