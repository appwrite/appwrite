import { Captions, Play } from 'lucide-react'
import {
  ArtChip,
  ArtPanel,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const OPTIONS = [
  { id: 'autoplay', label: 'Autoplay', value: 'Enabled', translateValue: true },
  { id: 'start', label: 'Custom start time', value: '00:12', translateValue: false },
  { id: 'captions', label: 'Captions', value: 'English', translateValue: true },
  { id: 'quality', label: 'Quality', value: 'Auto', translateValue: true },
] as const

export function VideosPlayerVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] pb-14">
      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(80)}
        title={t('Player')}
        trailing={
          <span dir="ltr" className="rounded bg-[rgb(var(--tone-rgb)/0.16)] px-1.5 py-0.5 text-[10px] font-medium text-[var(--tone-ink)]">
            {t('Auto')} · 1080p
          </span>
        }
        bodyClassName="p-3"
      >
        <div className="relative aspect-video overflow-hidden rounded-lg bg-[linear-gradient(165deg,rgb(var(--tone2-rgb)/0.7),rgb(var(--tone-rgb)/0.45)_55%,#17171b)]">
          <span className="absolute left-1/2 top-1/2 flex size-10 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-white/20 bg-background/80">
            <Play className="size-4 text-foreground" aria-hidden />
          </span>
          <div className="absolute inset-x-3 bottom-3">
            <div className="flex items-center gap-2" dir="ltr">
              <span className="font-mono text-[10px] text-white/80">00:12</span>
              <div className="h-1 flex-1 overflow-hidden rounded-full bg-white/25">
                <div className="h-full w-[18%] rounded-full bg-white" />
              </div>
              <span className="font-mono text-[10px] text-white/80">02:14</span>
              <Captions className="size-3.5 text-white" aria-hidden />
            </div>
          </div>
        </div>
      </ArtWindow>

      <div className="mt-2 grid grid-cols-2 gap-2">
        {OPTIONS.map((option, index) => (
          <ArtPanel
            key={option.id}
            delayMs={280 + index * 80}
            innerClassName="px-2.5 py-2"
          >
            <p className="truncate text-[10px] text-muted-foreground">{t(option.label)}</p>
            <p
              dir={option.id === 'start' ? 'ltr' : undefined}
              className={cn('mt-0.5 text-[12px] font-medium text-foreground')}
            >
              {option.translateValue ? t(option.value) : option.value}
            </p>
          </ArtPanel>
        ))}
      </div>

      <ArtChip className="bottom-0 end-[6%]" delayMs={900} floatDelayMs={600}>
        <p className="text-[11px] font-medium text-foreground">{t('First frame under 100 ms')}</p>
        <p className="text-[10px] text-muted-foreground">{t('Embed on any page')}</p>
      </ArtChip>
    </div>
  )
}
