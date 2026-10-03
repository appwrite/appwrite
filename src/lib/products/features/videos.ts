import type { ProductFeatureContent } from '@/lib/products/features/types'

export const videosProductFeatures: ProductFeatureContent[] = [
  {
    id: 'source',
    title: 'Start from any file in Storage',
    description:
      'Create a video from a file that is already in a Storage bucket. The first encoding job probes codecs, resolution, frame rate, duration, and bitrate, so you never parse media metadata yourself.',
  },
  {
    id: 'renditions',
    title: 'Encode an adaptive bitrate ladder',
    description:
      'Every new project starts with a default ladder of encoding profiles. Renditions encode in the background and report status and progress until they are ready to stream. Add your own profiles when you need a different resolution or bitrate.',
  },
  {
    id: 'outputs',
    title: 'HLS, DASH, and CMAF from one source',
    description:
      'Package each rendition for the players you support. Every output has a master manifest that players follow to the right rendition for each viewer and connection.',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
  },
  {
    id: 'subtitles',
    title: 'Subtitles, uploaded or extracted',
    description:
      'Upload WebVTT or SubRip files from Storage, or use the tracks already embedded in the source. Tag each track with a language, choose a default, and players pick it up from the manifest.',
  },
  {
    id: 'timeline',
    title: 'Scrubbing thumbnails on the timeline',
    description:
      'Generate sprite sheets with a WebVTT index so viewers see a preview frame while they scrub. The same sprites give every video a poster image.',
  },
  {
    id: 'playback',
    title: 'Play anywhere',
    description:
      'Hand the master manifest URL to hls.js, dash.js, Shaka Player, AVPlayer, or ExoPlayer. Or copy an embed snippet from the Console and drop it into any page.',
  },
]
