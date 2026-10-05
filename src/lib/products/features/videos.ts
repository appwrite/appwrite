import type { ProductFeatureContent } from '@/lib/products/features/types'

export const videosProductFeatures: ProductFeatureContent[] = [
  {
    id: 'source',
    title: 'Start from any file in Storage',
    description:
      'Create a video from a file that is already in a Storage bucket. The first encoding job probes codecs, resolution, frame rate, duration, and bitrate, so you never parse media metadata yourself.',
    brandLight: 'pink',
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
    brandLight: 'teal',
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
    id: 'player',
    title: 'Embed a built-in player',
    description:
      'Copy an iframe from the Console and drop the Appwrite player on any page. Set a start time, choose captions, lock a rendition, or leave quality on Auto. The stream is designed for a first frame in under 100 ms.',
    brandLight: 'purple',
  },
  {
    id: 'playback',
    title: 'Use any player on any platform',
    description:
      'Prefer your own player? The same master manifest works with hls.js, Shaka Player, and Video.js on the web, AVPlayer on Apple platforms, ExoPlayer on Android, and the usual Flutter and React Native players.',
  },
  {
    id: 'performance',
    title: 'Press play, see picture in under 100 ms',
    description:
      'No waiting on the whole file. Streams open from a few kilobytes and one short segment, so picture lands before anyone notices.',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
    brandLight: 'teal',
  },
  {
    id: 'inspector',
    title: 'Inspect quality as viewers play',
    description:
      'Pop the inspector out beside any video in the Console. Watch a live experience score for that session, then drill into buffer, bitrate, dropped frames, and diagnostics when a stream misses the sub 100 ms first frame.',
    brandLight: 'pink',
  },
]
