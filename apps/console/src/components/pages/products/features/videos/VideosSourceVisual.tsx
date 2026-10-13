import { FileVideo, ScanSearch } from 'lucide-react'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const PROBED_METADATA = [
  { label: 'Resolution', value: '1920x1080' },
  { label: 'Duration', value: '02:14' },
  { label: 'Frame rate', value: '30 fps' },
  { label: 'Video codec', value: 'H.264' },
  { label: 'Audio codec', value: 'AAC' },
  { label: 'Bitrate', value: '8.4 Mbps' },
] as const

export function VideosSourceVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[460px] pb-12 pt-2">
      <ArtPanel innerClassName="product-tone-shadow p-3.5" delayMs={60}>
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Storage')}
        </p>
        <div className="mt-2.5 flex items-center gap-2.5 rounded-lg border border-border bg-muted/20 px-2.5 py-2">
          <ArtIconBadge icon={FileVideo} tone="secondary" />
          <div className="min-w-0 flex-1">
            <p dir="ltr" className="truncate text-[12px] font-semibold text-foreground">
              launch.mp4
            </p>
            <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">
              media · 84 MB
            </p>
          </div>
          <span className="shrink-0 text-[10px] text-muted-foreground">{t('Source file')}</span>
        </div>
      </ArtPanel>

      <div className="flex justify-center py-1" aria-hidden>
        <ArtConnector orientation="vertical" className="h-8" />
      </div>

      <ArtPanel innerClassName="product-tone-shadow p-3.5" delayMs={360}>
        <div className="flex items-center gap-2.5">
          <ArtIconBadge icon={ScanSearch} />
          <div>
            <p className="text-[12px] font-semibold text-foreground">{t('Probed metadata')}</p>
            <p className="text-[10px] text-muted-foreground">{t('From the first encode')}</p>
          </div>
        </div>
        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
          {PROBED_METADATA.map((item) => (
            <div key={item.label} className="rounded-md border border-border px-2.5 py-1.5">
              <p className="text-[10px] text-muted-foreground">{t(item.label)}</p>
              <p dir="ltr" className="font-mono text-[12px] font-medium text-foreground">
                {item.value}
              </p>
            </div>
          ))}
        </div>
      </ArtPanel>

      <ArtChip className="bottom-0 start-[8%]" delayMs={900} floatDelayMs={600}>
        <p className="text-[11px] font-medium text-foreground">{t('Source stays in Storage')}</p>
        <p className="text-[10px] text-muted-foreground">{t('The original file is never replaced')}</p>
      </ArtChip>
    </div>
  )
}
