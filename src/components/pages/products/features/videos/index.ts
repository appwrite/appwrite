import type { ComponentType } from 'react'
import { VideosInspectorVisual } from './VideosInspectorVisual'
import { VideosPlaybackVisual } from './VideosPlaybackVisual'
import { VideosPlayerVisual } from './VideosPlayerVisual'
import { VideosRenditionsVisual } from './VideosRenditionsVisual'
import { VideosSourceVisual } from './VideosSourceVisual'
import { VideosSubtitlesVisual } from './VideosSubtitlesVisual'
import { VideosTimelineVisual } from './VideosTimelineVisual'

export const VIDEOS_FEATURE_VISUALS: Record<string, ComponentType> = {
  source: VideosSourceVisual,
  renditions: VideosRenditionsVisual,
  subtitles: VideosSubtitlesVisual,
  timeline: VideosTimelineVisual,
  player: VideosPlayerVisual,
  playback: VideosPlaybackVisual,
  inspector: VideosInspectorVisual,
}
