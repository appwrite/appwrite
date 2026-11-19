import { Captions } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtIconBadge,
  ArtPanel,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const TRACKS = [
  { id: 'eng', name: 'English', code: 'eng', source: 'WebVTT', isDefault: true, embedded: false },
  { id: 'heb', name: 'Hebrew', code: 'heb', source: 'SubRip', isDefault: false, embedded: false },
  { id: 'und', name: 'Track 3', code: 'und', source: null, isDefault: false, embedded: true },
] as const

export function VideosSubtitlesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[500px] pb-16 pt-2">
      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(60)}
        title={t('Subtitles')}
        trailing={
          <Badge variant="info" className="text-[10px]">
            {t('Default')}
          </Badge>
        }
        bodyClassName="p-3"
      >
        <div className="relative aspect-[16/9] overflow-hidden rounded-lg bg-[linear-gradient(165deg,rgb(var(--tone-rgb)/0.45),#17171b_70%)]">
          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-3 pb-3 pt-8">
            <p className="text-center text-[12px] font-medium text-white">
              {t('Welcome to the launch')}
            </p>
          </div>
        </div>
      </ArtWindow>

      <div className="mt-3 space-y-2">
        {TRACKS.map((track, index) => (
          <ArtPanel
            key={track.id}
            delayMs={280 + index * 120}
            innerClassName="flex items-center justify-between gap-3 px-3 py-2"
          >
            <div className="flex min-w-0 items-center gap-2.5">
              <ArtIconBadge icon={Captions} tone={track.isDefault ? 'primary' : 'neutral'} />
              <div className="min-w-0">
                <p className="truncate text-[12px] font-medium text-foreground">
                  {track.id === 'und' ? track.name : t(track.name)}
                </p>
                <p dir="ltr" className="truncate font-mono text-[10px] text-muted-foreground">
                  {track.code}
                  {track.source ? ` · ${track.source}` : ''}
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
          </ArtPanel>
        ))}
      </div>

      <ArtChip className="bottom-0 end-[4%] max-w-[240px]" delayMs={900} floatDelayMs={700}>
        <p className="text-[11px] font-medium text-foreground">{t('Upload or extract')}</p>
        <p className="text-[10px] leading-4 text-muted-foreground">
          {t('Retag an embedded track without replacing the file')}
        </p>
      </ArtChip>
    </div>
  )
}
