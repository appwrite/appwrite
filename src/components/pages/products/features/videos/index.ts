import type { ComponentType } from 'react'
import { VideosPlaybackVisual } from './VideosPlaybackVisual'
import { VideosRenditionsVisual } from './VideosRenditionsVisual'
import { VideosSourceVisual } from './VideosSourceVisual'
import { VideosSubtitlesVisual } from './VideosSubtitlesVisual'
import { VideosTimelineVisual } from './VideosTimelineVisual'

export const VIDEOS_FEATURE_VISUALS: Record<string, ComponentType> = {
  source: VideosSourceVisual,
  renditions: VideosRenditionsVisual,
  subtitles: VideosSubtitlesVisual,
  timeline: VideosTimelineVisual,
  playback: VideosPlaybackVisual,
}
