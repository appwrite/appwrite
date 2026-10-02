import { useCallback, useEffect, useRef, useState, type RefObject } from 'react'
import type Hls from 'hls.js'
import type { ErrorData, Level, LevelDetails, MediaPlaylist } from 'hls.js'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'

const MAX_EVENTS = 200
const MAX_FRAGMENTS = 60
const STATS_INTERVAL_MS = 500

/**
 * Manifests reference absolute `/v1/videos/...` paths carrying only `?project=`.
 * Console sessions need admin mode (the console user is not in the file's read
 * roles), so every playlist, segment, and init request is rewritten here.
 */
export function withConsoleVideoAccess(url: string): string {
  if (!url || url.includes('mode=admin')) return url
  return withAdminMode(url)
}

export type HlsLevelInfo = {
  index: number
  width: number
  height: number
  bitrate: number
  averageBitrate: number
  frameRate: number
  videoCodec?: string
  audioCodec?: string
  name: string
  url: string
}

export type HlsLevelDetailsInfo = {
  level: number
  version: number | null
  type: string | null
  live: boolean
  targetDuration: number
  totalDuration: number
  fragments: number
  startSN: number
  endSN: number
  loadedAt: number
}

export type HlsFragmentInfo = {
  sn: number | string
  level: number
  type: string
  start: number
  duration: number
  bytes: number
  loadMs: number
  firstByteMs: number
  url: string
  at: number
}

export type HlsEventEntry = {
  at: number
  kind: 'info' | 'warning' | 'error'
  name: string
  detail?: string
}

export type HlsTrackInfo = {
  id: number
  name: string
  lang?: string
  default: boolean
}

export type PlaybackStats = {
  currentTime: number
  duration: number
  bufferedAhead: number
  bufferedRanges: Array<[number, number]>
  readyState: number
  networkState: number
  paused: boolean
  playbackRate: number
  videoWidth: number
  videoHeight: number
  droppedFrames: number
  totalFrames: number
  bandwidthEstimate: number | null
  currentLevel: number
  loadLevel: number
  nextLevel: number
  autoLevelEnabled: boolean
  latency: number | null
}

export type HlsPlayerState = {
  engine: 'hls.js' | 'native' | null
  hlsVersion: string | null
  levels: HlsLevelInfo[]
  levelDetails: Record<number, HlsLevelDetailsInfo>
  audioTracks: HlsTrackInfo[]
  subtitleTracks: HlsTrackInfo[]
  currentAudioTrack: number
  currentSubtitleTrack: number
  fragments: HlsFragmentInfo[]
  events: HlsEventEntry[]
  stats: PlaybackStats | null
  fatalError: string | null
  loadStartedAt: number | null
  manifestLoadedAt: number | null
  firstFrameAt: number | null
}

const INITIAL_STATE: HlsPlayerState = {
  engine: null,
  hlsVersion: null,
  levels: [],
  levelDetails: {},
  audioTracks: [],
  subtitleTracks: [],
  currentAudioTrack: -1,
  currentSubtitleTrack: -1,
  fragments: [],
  events: [],
  stats: null,
  fatalError: null,
  loadStartedAt: null,
  manifestLoadedAt: null,
  firstFrameAt: null,
}

function toLevelInfo(level: Level, index: number): HlsLevelInfo {
  return {
    index,
    width: level.width,
    height: level.height,
    bitrate: level.bitrate,
    averageBitrate: level.averageBitrate,
    frameRate: level.frameRate,
    videoCodec: level.videoCodec,
    audioCodec: level.audioCodec,
    name: level.name,
    url: level.uri,
  }
}

function toTrackInfo(track: MediaPlaylist): HlsTrackInfo {
  return {
    id: track.id,
    name: track.name,
    lang: track.lang,
    default: track.default,
  }
}

function toDetailsInfo(
  level: number,
  details: LevelDetails,
): HlsLevelDetailsInfo {
  return {
    level,
    version: details.version,
    type: details.type,
    live: details.live,
    targetDuration: details.targetduration,
    totalDuration: details.totalduration,
    fragments: details.fragments.length,
    startSN: details.startSN,
    endSN: details.endSN,
    loadedAt: Date.now(),
  }
}

function describeError(data: ErrorData): string {
  const status = data.response?.code ? ` (HTTP ${data.response.code})` : ''
  const message = data.error?.message ? `: ${data.error.message}` : ''
  return `${data.type} / ${data.details}${status}${message}`
}

function readBuffered(video: HTMLVideoElement): Array<[number, number]> {
  const ranges: Array<[number, number]> = []
  for (let i = 0; i < video.buffered.length; i++) {
    ranges.push([video.buffered.start(i), video.buffered.end(i)])
  }
  return ranges
}

/** hls.js 1.7+ uses FetchLoader in modern browsers; xhrSetup alone is not enough. */
function consoleHlsLoaderConfig(): {
  enableWorker: boolean
  fetchSetup: (context: { url: string }, initParams: RequestInit) => Request
  xhrSetup: (xhr: XMLHttpRequest, url: string) => void
} {
  return {
    enableWorker: true,
    fetchSetup: (context, initParams) => {
      initParams.credentials = 'include'
      return new Request(withConsoleVideoAccess(context.url), initParams)
    },
    xhrSetup: (xhr, url) => {
      xhr.open('GET', withConsoleVideoAccess(url), true)
      xhr.withCredentials = true
    },
  }
}

function ensurePlaybackAudio(hls: Hls | null) {
  if (!hls || hls.audioTracks.length === 0) return
  if (hls.audioTrack >= 0) return
  const defaultIndex = hls.audioTracks.findIndex((track) => track.default)
  hls.audioTrack = defaultIndex >= 0 ? defaultIndex : 0
}

/**
 * Attach an HLS source to a `<video>` element with hls.js and collect the
 * telemetry shown in the stream debug panel (levels, playlists, fragments,
 * events, buffer and frame stats).
 */
export type PlayerSource = { url: string; type: 'hls' | 'file' }

export function useHlsPlayer(
  videoRef: RefObject<HTMLVideoElement | null>,
  source: PlayerSource | null,
  reloadToken: number,
) {
  const src = source?.url ?? null
  const sourceType = source?.type ?? null
  const hlsRef = useRef<Hls | null>(null)
  const [state, setState] = useState<HlsPlayerState>(INITIAL_STATE)

  const pushEvent = useCallback((entry: Omit<HlsEventEntry, 'at'>) => {
    setState((prev) => ({
      ...prev,
      events: [{ ...entry, at: Date.now() }, ...prev.events].slice(
        0,
        MAX_EVENTS,
      ),
    }))
  }, [])

  useEffect(() => {
    const video = videoRef.current
    if (!video || !src) {
      setState(INITIAL_STATE)
      return
    }

    let cancelled = false
    let hls: Hls | null = null
    setState({ ...INITIAL_STATE, loadStartedAt: Date.now() })

    const onFirstFrame = () => {
      setState((prev) =>
        prev.firstFrameAt ? prev : { ...prev, firstFrameAt: Date.now() },
      )
    }
    video.addEventListener('loadeddata', onFirstFrame)

    if (sourceType === 'file') {
      video.src = src
      setState((prev) => ({ ...prev, engine: 'native' }))
      pushEvent({ kind: 'info', name: 'LOAD_FILE', detail: src })
      return () => {
        video.removeEventListener('loadeddata', onFirstFrame)
        video.removeAttribute('src')
        video.load()
      }
    }

    void import('hls.js').then(({ default: HlsClass }) => {
      if (cancelled) return

      if (!HlsClass.isSupported()) {
        if (video.canPlayType('application/vnd.apple.mpegurl')) {
          video.src = withConsoleVideoAccess(src)
          setState((prev) => ({ ...prev, engine: 'native' }))
          pushEvent({
            kind: 'warning',
            name: 'Native HLS',
            detail:
              'Media Source Extensions are unavailable; segment requests use browser cookies only.',
          })
        } else {
          setState((prev) => ({
            ...prev,
            fatalError: 'HLS playback is not supported in this browser.',
          }))
        }
        return
      }

      hls = new HlsClass(consoleHlsLoaderConfig())
      hlsRef.current = hls
      setState((prev) => ({
        ...prev,
        engine: 'hls.js',
        hlsVersion: HlsClass.version,
      }))

      const E = HlsClass.Events

      hls.on(E.MANIFEST_PARSED, (_event, data) => {
        ensurePlaybackAudio(hls)
        setState((prev) => ({
          ...prev,
          manifestLoadedAt: Date.now(),
          levels: data.levels.map(toLevelInfo),
          audioTracks: data.audioTracks.map(toTrackInfo),
          currentAudioTrack: hls?.audioTrack ?? -1,
        }))
        pushEvent({
          kind: 'info',
          name: 'MANIFEST_PARSED',
          detail: `${data.levels.length} level(s), audio ${data.audioTracks.length}, subtitles ${data.subtitleTracks.length}`,
        })
      })
      hls.on(E.LEVEL_LOADED, (_event, data) => {
        setState((prev) => ({
          ...prev,
          levelDetails: {
            ...prev.levelDetails,
            [data.level]: toDetailsInfo(data.level, data.details),
          },
        }))
      })
      hls.on(E.LEVEL_SWITCHED, (_event, data) => {
        const level = hls?.levels[data.level]
        pushEvent({
          kind: 'info',
          name: 'LEVEL_SWITCHED',
          detail: level
            ? `#${data.level} ${level.width}×${level.height} @ ${Math.round(level.bitrate / 1000)} kbps`
            : `#${data.level}`,
        })
      })
      hls.on(E.AUDIO_TRACKS_UPDATED, (_event, data) => {
        ensurePlaybackAudio(hls)
        setState((prev) => ({
          ...prev,
          audioTracks: data.audioTracks.map(toTrackInfo),
          currentAudioTrack: hls?.audioTrack ?? prev.currentAudioTrack,
        }))
      })
      hls.on(E.AUDIO_TRACK_SWITCHED, (_event, data) => {
        setState((prev) => ({ ...prev, currentAudioTrack: data.id }))
      })
      hls.on(E.SUBTITLE_TRACKS_UPDATED, (_event, data) => {
        setState((prev) => ({
          ...prev,
          subtitleTracks: data.subtitleTracks.map(toTrackInfo),
        }))
      })
      hls.on(E.SUBTITLE_TRACK_SWITCH, (_event, data) => {
        setState((prev) => ({ ...prev, currentSubtitleTrack: data.id }))
        pushEvent({
          kind: 'info',
          name: 'SUBTITLE_TRACK_SWITCH',
          detail: data.id < 0 ? 'off' : `#${data.id}`,
        })
      })
      hls.on(E.FRAG_LOADED, (_event, data) => {
        const { frag } = data
        const loading = frag.stats.loading
        const entry: HlsFragmentInfo = {
          sn: frag.sn,
          level: frag.level,
          type: frag.type,
          start: frag.start,
          duration: frag.duration,
          bytes: frag.stats.loaded,
          loadMs: Math.max(0, loading.end - loading.start),
          firstByteMs: Math.max(0, loading.first - loading.start),
          url: frag.url,
          at: Date.now(),
        }
        setState((prev) => ({
          ...prev,
          fragments: [entry, ...prev.fragments].slice(0, MAX_FRAGMENTS),
        }))
      })
      hls.on(E.ERROR, (_event, data) => {
        pushEvent({
          kind: data.fatal ? 'error' : 'warning',
          name: data.fatal ? 'FATAL_ERROR' : 'ERROR',
          detail: describeError(data),
        })
        if (!data.fatal || !hls) return
        if (data.type === HlsClass.ErrorTypes.NETWORK_ERROR) {
          setState((prev) => ({ ...prev, fatalError: describeError(data) }))
          hls.startLoad()
        } else if (data.type === HlsClass.ErrorTypes.MEDIA_ERROR) {
          hls.recoverMediaError()
        } else {
          setState((prev) => ({ ...prev, fatalError: describeError(data) }))
          hls.destroy()
          hlsRef.current = null
        }
      })

      hls.attachMedia(video)
      hls.loadSource(withConsoleVideoAccess(src))
      pushEvent({ kind: 'info', name: 'LOAD_SOURCE', detail: src })
    })

    return () => {
      cancelled = true
      video.removeEventListener('loadeddata', onFirstFrame)
      hls?.destroy()
      hlsRef.current = null
      video.removeAttribute('src')
      video.load()
    }
  }, [videoRef, src, sourceType, reloadToken, pushEvent])

  useEffect(() => {
    if (!src) return
    const interval = window.setInterval(() => {
      const video = videoRef.current
      if (!video) return
      const hls = hlsRef.current
      const ranges = readBuffered(video)
      const current = video.currentTime
      const containing = ranges.find(([s, e]) => current >= s && current <= e)
      const quality =
        typeof video.getVideoPlaybackQuality === 'function'
          ? video.getVideoPlaybackQuality()
          : null
      const stats: PlaybackStats = {
        currentTime: current,
        duration: video.duration,
        bufferedAhead: containing ? containing[1] - current : 0,
        bufferedRanges: ranges,
        readyState: video.readyState,
        networkState: video.networkState,
        paused: video.paused,
        playbackRate: video.playbackRate,
        videoWidth: video.videoWidth,
        videoHeight: video.videoHeight,
        droppedFrames: quality?.droppedVideoFrames ?? 0,
        totalFrames: quality?.totalVideoFrames ?? 0,
        bandwidthEstimate: hls ? hls.bandwidthEstimate : null,
        currentLevel: hls ? hls.currentLevel : -1,
        loadLevel: hls ? hls.loadLevel : -1,
        nextLevel: hls ? hls.nextLevel : -1,
        autoLevelEnabled: hls ? hls.autoLevelEnabled : true,
        latency: hls && Number.isFinite(hls.latency) ? hls.latency : null,
      }
      setState((prev) => ({ ...prev, stats }))
    }, STATS_INTERVAL_MS)
    return () => window.clearInterval(interval)
  }, [videoRef, src, reloadToken])

  const setLevel = useCallback((level: number) => {
    const hls = hlsRef.current
    if (!hls) return
    hls.currentLevel = level
  }, [])

  const setSubtitleTrack = useCallback((id: number) => {
    const hls = hlsRef.current
    if (!hls) return
    hls.subtitleDisplay = id >= 0
    hls.subtitleTrack = id
  }, [])

  const setAudioTrack = useCallback((id: number) => {
    const hls = hlsRef.current
    if (!hls) return
    hls.audioTrack = id
  }, [])

  const clearEvents = useCallback(() => {
    setState((prev) => ({ ...prev, events: [], fragments: [] }))
  }, [])

  return { state, setLevel, setSubtitleTrack, setAudioTrack, clearEvents }
}
