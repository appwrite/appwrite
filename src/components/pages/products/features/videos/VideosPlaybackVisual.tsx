import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const HLS_SNIPPET = `const url = videos.getHlsManifest({
  videoId: '<VIDEO_ID>',
});

const hls = new Hls();
hls.loadSource(url);
hls.attachMedia(document.querySelector('video'));`

const PLAYERS = ['hls.js', 'dash.js', 'Shaka Player', 'AVPlayer', 'ExoPlayer'] as const

export function VideosPlaybackVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'hls', label: 'HLS', active: true },
        { id: 'dash', label: 'DASH' },
        { id: 'embed', label: 'Embed' },
      ]}
    >
      <div className="space-y-4">
        <pre
          dir="ltr"
          className="overflow-x-auto rounded-lg border border-border bg-muted/20 px-3 py-2.5 font-mono text-[11px] leading-5 text-foreground"
        >
          <code>{HLS_SNIPPET}</code>
        </pre>
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Works with')}
          </p>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {PLAYERS.map((player) => (
              <Badge key={player} variant="inactive" className="text-[10px]">
                {player}
              </Badge>
            ))}
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
