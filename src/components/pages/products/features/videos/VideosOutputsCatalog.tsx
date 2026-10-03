import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'

type OutputTile = {
  id: string
  title: string
  description: string
  segments: string
  manifests: string[]
}

const OUTPUT_TILES: OutputTile[] = [
  {
    id: 'hls',
    title: 'HLS',
    description: 'For Safari, iOS, tvOS, and hls.js on every other browser.',
    segments: 'MPEG-TS',
    manifests: ['outputs/hls/master.m3u8'],
  },
  {
    id: 'dash',
    title: 'DASH',
    description: 'For dash.js, Shaka Player, ExoPlayer, and smart TVs.',
    segments: 'fMP4',
    manifests: ['outputs/dash/master.mpd'],
  },
  {
    id: 'cmaf',
    title: 'CMAF',
    description: 'Encode fMP4 segments once and serve both an HLS and a DASH master.',
    segments: 'fMP4',
    manifests: ['outputs/cmaf/master.m3u8', 'outputs/cmaf/master.mpd'],
  },
]

export function VideosOutputsCatalog() {
  const t = useT()
  return (
    <div className="space-y-4">
      <p className="text-center text-[13px] leading-5 text-muted-foreground sm:text-[14px]">
        {t('One rendition per profile and output. Encode the same profile for more than one output.')}
      </p>
      <div className="flex flex-wrap justify-center gap-3">
        {OUTPUT_TILES.map((output) => (
          <div
            key={output.id}
            className="w-full sm:w-[calc((100%-0.75rem)/2)] lg:w-[calc((100%-1.5rem)/3)]"
          >
            <div className="h-full rounded-xl border border-border bg-background/80 p-4">
              <div className="flex items-start justify-between gap-3">
                <h4 className="text-[14px] font-semibold text-foreground">{output.title}</h4>
                <Badge variant="info" className="shrink-0 text-[10px]">
                  {output.segments}
                </Badge>
              </div>
              <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">
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
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
