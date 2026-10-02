import { useMemo, type RefObject } from 'react'
import {
  inferSegmentMediaKindForUrl,
  useDashPlayer,
  type StreamPlayerState,
  type StreamEventEntry,
  type StreamLevelDetailsInfo,
  type StreamSegmentInfo,
  type StreamSegmentMediaKind,
  type StreamTrackInfo,
  type StreamVariantInfo,
  type PlaybackStats,
  withConsoleVideoAccess,
} from './useDashPlayer'
import { useHlsPlayer, type HlsPlayerState } from './useHlsPlayer'

export type {
  StreamPlayerState,
  StreamEventEntry,
  StreamLevelDetailsInfo,
  StreamSegmentInfo,
  StreamSegmentMediaKind,
  StreamTrackInfo,
  StreamVariantInfo,
  PlaybackStats,
}
export { withConsoleVideoAccess }

export type PlayerSource =
  | { url: string; type: 'file' }
  | { url: string; type: 'hls' }
  | { url: string; type: 'dash' }

function mapHlsState(state: HlsPlayerState): StreamPlayerState {
  return {
    engine: state.engine,
    playerVersion: state.hlsVersion,
    levels: state.levels.map((level, index) => ({
      ...level,
      trackId: index,
    })),
    levelDetails: state.levelDetails,
    audioTracks: state.audioTracks,
    subtitleTracks: state.subtitleTracks,
    currentAudioTrack: state.currentAudioTrack,
    currentSubtitleTrack: state.currentSubtitleTrack,
    fragments: state.fragments.map((fragment) => ({
      ...fragment,
      mediaKind: inferSegmentMediaKindForUrl(fragment.url, fragment.type),
    })),
    events: state.events,
    stats: state.stats,
    fatalError: state.fatalError,
    loadStartedAt: state.loadStartedAt,
    manifestLoadedAt: state.manifestLoadedAt,
    firstFrameAt: state.firstFrameAt,
  }
}

/**
 * HLS and progressive files use hls.js; DASH uses Shaka Player.
 */
export function useStreamPlayer(
  videoRef: RefObject<HTMLVideoElement | null>,
  source: PlayerSource | null,
  reloadToken: number,
) {
  const hlsSource = useMemo(() => {
    if (!source || source.type === 'dash') return null
    return {
      url: source.url,
      type: source.type === 'file' ? ('file' as const) : ('hls' as const),
    }
  }, [source])

  const dashSource = useMemo(() => {
    if (source?.type !== 'dash') return null
    return { url: source.url, type: 'dash' as const }
  }, [source])

  const hls = useHlsPlayer(videoRef, hlsSource, reloadToken)
  const dash = useDashPlayer(videoRef, dashSource, reloadToken)
  const hlsState = useMemo(() => mapHlsState(hls.state), [hls.state])

  if (source?.type === 'dash') return dash

  return {
    ...hls,
    state: hlsState,
  }
}
