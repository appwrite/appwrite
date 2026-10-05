import { Monitor, Smartphone, Tablet } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import {
  ArtChip,
  ArtIconBadge,
  ArtPanel,
  ArtToken as T,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const PLATFORMS: { id: string; label: string; players: string; icon: LucideIcon }[] = [
  { id: 'web', label: 'Web', players: 'hls.js · Shaka · Video.js', icon: Monitor },
  { id: 'apple', label: 'Apple', players: 'AVPlayer', icon: Tablet },
  { id: 'android', label: 'Android', players: 'ExoPlayer', icon: Smartphone },
  { id: 'native', label: 'Flutter · React Native', players: 'video_player · react-native-video', icon: Smartphone },
]

export function VideosPlaybackVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] pb-4 pt-12">
      <ArtChip className="start-0 top-0" delayMs={80}>
        <p className="text-[11px] font-medium text-foreground">{t('Master manifest URL')}</p>
        <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          videos.getHlsManifest()
        </p>
      </ArtChip>

      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(160)}
        title={<span dir="ltr">player.ts</span>}
        bodyClassName="px-4 py-3.5"
      >
        <pre dir="ltr" className="overflow-x-auto font-mono text-[11px] leading-[1.85]">
          <code>
            <T tone="keyword">const</T> <T tone="identifier">url</T> <T tone="operator">=</T>{' '}
            <T tone="identifier">videos</T>
            <T tone="punctuation">.</T>
            <T tone="function">getHlsManifest</T>
            <T tone="punctuation">({'{ '}</T>
            <T tone="property">videoId</T>
            <T tone="punctuation"> </T>
            <T tone="string">&apos;&lt;VIDEO_ID&gt;&apos;</T>
            <T tone="punctuation">{' })'}</T>
            {'\n\n'}
            <T tone="keyword">const</T> <T tone="identifier">hls</T> <T tone="operator">=</T>{' '}
            <T tone="keyword">new</T> <T tone="class">Hls</T>
            <T tone="punctuation">()</T>
            {'\n'}
            <T tone="identifier">hls</T>
            <T tone="punctuation">.</T>
            <T tone="function">loadSource</T>
            <T tone="punctuation">(</T>
            <T tone="identifier">url</T>
            <T tone="punctuation">)</T>
            {'\n'}
            <T tone="identifier">hls</T>
            <T tone="punctuation">.</T>
            <T tone="function">attachMedia</T>
            <T tone="punctuation">(</T>
            <T tone="identifier">video</T>
            <T tone="punctuation">)</T>
          </code>
        </pre>
      </ArtWindow>

      <div className="mt-3 grid gap-2 sm:grid-cols-2">
        {PLATFORMS.map((platform, index) => (
          <ArtPanel
            key={platform.id}
            delayMs={420 + index * 90}
            float
            floatDelayMs={index * 280}
            innerClassName="flex items-center gap-2.5 px-3 py-2"
          >
            <ArtIconBadge icon={platform.icon} tone={index === 0 ? 'primary' : 'neutral'} />
            <div className="min-w-0">
              <p className="truncate text-[11px] font-medium text-foreground">{t(platform.label)}</p>
              <p dir="ltr" className="truncate font-mono text-[10px] text-muted-foreground">
                {platform.players}
              </p>
            </div>
          </ArtPanel>
        ))}
      </div>
    </div>
  )
}
