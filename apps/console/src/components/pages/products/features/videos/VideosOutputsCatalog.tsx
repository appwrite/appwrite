import { Layers, MonitorPlay, Radio, type LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

type OutputTile = {
  id: string
  title: string
  description: string
  segments: string
  icon: LucideIcon
  manifests: string[]
  players: string[]
}

const OUTPUT_TILES: OutputTile[] = [
  {
    id: 'hls',
    title: 'HLS',
    description: 'For Safari, iOS, tvOS, and hls.js on every other browser.',
    segments: 'MPEG-TS',
    icon: Radio,
    manifests: ['outputs/hls/master.m3u8'],
    players: ['hls.js', 'AVPlayer', 'Safari'],
  },
  {
    id: 'dash',
    title: 'DASH',
    description: 'For dash.js, Shaka Player, ExoPlayer, and smart TVs.',
    segments: 'fMP4',
    icon: MonitorPlay,
    manifests: ['outputs/dash/master.mpd'],
    players: ['dash.js', 'Shaka', 'ExoPlayer'],
  },
  {
    id: 'cmaf',
    title: 'CMAF',
    description: 'Encode fMP4 segments once and serve both an HLS and a DASH master.',
    segments: 'fMP4',
    icon: Layers,
    manifests: ['outputs/cmaf/master.m3u8', 'outputs/cmaf/master.mpd'],
    players: ['HLS + DASH'],
  },
]

export function VideosOutputsCatalog() {
  const t = useT()

  return (
    <div className="space-y-8">
      <div
        className="product-hero-rise flex justify-center"
        style={riseStyle(0)}
      >
        <div className="inline-flex items-center gap-2 rounded-full border border-border bg-background px-3 py-1.5 shadow-sm dark:bg-card">
          <span
            className="size-1.5 rounded-full bg-[var(--tone-ink)] shadow-[0_0_8px_rgb(var(--tone-rgb))]"
            aria-hidden
          />
          <span className="text-center text-[12px] text-muted-foreground sm:text-[13px]">
            {t('One rendition per profile and output. Encode the same profile for more than one output.')}
          </span>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {OUTPUT_TILES.map((output, index) => {
          const Icon = output.icon
          return (
            <ArtPanel
              key={output.id}
              className="h-full"
              innerClassName="flex h-full flex-col p-4"
              delayMs={120 + index * 120}
              float
              floatDelayMs={index * 420}
            >
              <div className="flex items-start justify-between gap-3">
                <ArtIconBadge icon={Icon} tone={index === 2 ? 'secondary' : 'primary'} className="size-8" />
                <Badge variant="info" className="shrink-0 text-[10px]">
                  {output.segments}
                </Badge>
              </div>
              <h4 className="mt-3 text-[14px] font-semibold text-foreground">{output.title}</h4>
              <p className="mt-1 text-[12.5px] leading-5 text-muted-foreground">
                {t(output.description)}
              </p>
              <div className="mt-3 space-y-1">
                {output.manifests.map((manifest) => (
                  <code
                    key={manifest}
                    dir="ltr"
                    className="block truncate rounded-md border border-border bg-muted/30 px-2 py-1 font-mono text-[10px] text-muted-foreground"
                  >
                    {manifest}
                  </code>
                ))}
              </div>
              <p className="mt-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Works with')}
              </p>
              <p dir="ltr" className="mt-1 font-mono text-[11px] text-foreground">
                {output.players.join(' · ')}
              </p>
            </ArtPanel>
          )
        })}
      </div>
    </div>
  )
}
