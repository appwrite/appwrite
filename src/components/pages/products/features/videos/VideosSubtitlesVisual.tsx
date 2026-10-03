import { Captions } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const TRACKS = [
  { id: 'eng', name: 'English', code: 'eng', source: 'WebVTT', isDefault: true, embedded: false },
  { id: 'heb', name: 'Hebrew', code: 'heb', source: 'SubRip', isDefault: false, embedded: false },
  { id: 'und', name: 'Track 3', code: 'und', source: null, isDefault: false, embedded: true },
] as const

export function VideosSubtitlesVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame title={t('Subtitles')}>
      <div className="space-y-2">
        {TRACKS.map((track) => (
          <div
            key={track.id}
            className="flex items-center justify-between gap-3 rounded-lg border border-border bg-background/80 px-3 py-2.5"
          >
            <div className="flex min-w-0 items-center gap-2.5">
              <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                <Captions className="size-3.5 text-muted-foreground" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className="truncate text-[12px] font-medium text-foreground">
                  {t(track.name)}
                </p>
                <p className="truncate font-mono text-[10px] text-muted-foreground">
                  {track.code}
                  {track.source ? ` / ${track.source}` : ''}
                </p>
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-1.5">
              {track.isDefault ? (
                <Badge variant="info" className="text-[10px]">
                  {t('Default')}
                </Badge>
              ) : null}
              {track.embedded ? (
                <Badge variant="inactive" className="text-[10px]">
                  {t('Embedded')}
                </Badge>
              ) : null}
              <Badge variant="success" className="text-[10px]">
                {t('Ready')}
              </Badge>
            </div>
          </div>
        ))}
        <div className="rounded-lg border border-dashed border-border bg-muted/10 px-3 py-2.5">
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Uploads are converted to WebVTT and segmented for streaming. Retag an embedded track from und to its real language without replacing the file.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
