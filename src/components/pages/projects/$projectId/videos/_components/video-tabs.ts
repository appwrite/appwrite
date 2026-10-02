import {
  Bug,
  Captions,
  GalleryHorizontal,
  Layers,
  MonitorPlay,
  Radio,
  Settings,
  type LucideIcon,
} from 'lucide-react'
import type { VideoGlossaryTerm } from '@/lib/videos/glossary'

export type VideoTab =
  | 'overview'
  | 'renditions'
  | 'subtitles'
  | 'timeline'
  | 'streaming'
  | 'debugger'
  | 'settings'

export type VideoTabGroup = 'video' | 'encoding' | 'delivery' | 'manage'

export type VideoTabDefinition = {
  id: VideoTab
  label: string
  icon: LucideIcon
  group: VideoTabGroup
  term?: VideoGlossaryTerm
}

/** Order here is the order of the submenu and the context menu. */
export const VIDEO_TABS: VideoTabDefinition[] = [
  { id: 'overview', label: 'Overview', icon: MonitorPlay, group: 'video' },
  {
    id: 'renditions',
    label: 'Renditions',
    icon: Layers,
    group: 'encoding',
    term: 'rendition',
  },
  {
    id: 'subtitles',
    label: 'Subtitles',
    icon: Captions,
    group: 'encoding',
  },
  {
    id: 'timeline',
    label: 'Timeline',
    icon: GalleryHorizontal,
    group: 'encoding',
    term: 'timeline',
  },
  {
    id: 'streaming',
    label: 'Streaming',
    icon: Radio,
    group: 'delivery',
    term: 'manifest',
  },
  { id: 'debugger', label: 'Debugger', icon: Bug, group: 'delivery' },
  { id: 'settings', label: 'Settings', icon: Settings, group: 'manage' },
]

export const VIDEO_TAB_GROUP_LABELS: Record<VideoTabGroup, string | null> = {
  video: null,
  encoding: 'Media',
  delivery: 'Playback',
  manage: null,
}

export const VIDEO_TAB_NEEDS_READY_RENDITION =
  'Available once a rendition is ready.'

/** English reason a tab can't be opened yet, or null when it is available. */
export function getVideoTabDisabledReason(
  tab: VideoTab,
  renditions: { status: string }[],
): string | null {
  if (tab !== 'streaming' && tab !== 'debugger') return null
  return renditions.some((r) => r.status === 'ready')
    ? null
    : VIDEO_TAB_NEEDS_READY_RENDITION
}

export function videoTabPath(tab: VideoTab) {
  return (
    tab === 'overview'
      ? '/projects/$projectId/videos/$videoId'
      : `/projects/$projectId/videos/$videoId/${tab}`
  ) as '/projects/$projectId/videos/$videoId'
}
